import { ref, shallowRef, computed } from 'vue';
import { useChatStore } from '../store';
import { chatableKey, chatableKeyOf } from '../chatable';
import { useCalls } from './useCalls';
import { useToast } from './useToast';
import { useMessages } from './useMessages';

const configuredIceServers = window.ChatConfig?.calls?.iceServers;
const callsEnabled = window.ChatConfig?.calls?.enabled !== false;
let iceServers = [
    { urls: 'stun:stun.l.google.com:19302' },
    ...(Array.isArray(configuredIceServers) ? configuredIceServers : []),
];
const RING_TIMEOUT_MS = 45000;
const MAX_QUEUED_ICE_CANDIDATES = 256;
const ICE_RESTART_DELAY_MS = 4000;
const MAX_ICE_RESTART_ATTEMPTS = 2;

// phase: idle | outgoing | incoming | connecting | live
const phase = ref('idle');
const conversationId = ref(null);
const callId = ref(null);
const callStartedAt = ref(null);
const callInitiator = ref(null);
const isGroup = ref(false);
const announcer = ref(null); // { type, id } — who we're ringing, or who's ringing us
const peers = shallowRef([]); // Keep native MediaStream instances unproxied for media elements.
const video = ref(false);
const screenShareStream = shallowRef(null);
const isScreenSharing = ref(false);
const muted = ref(false);
const camOn = ref(false);
const speakerOn = ref(true);
const minimized = ref(false);
const startedAt = ref(null);
const elapsed = ref(0);
const localStream = shallowRef(null);

// Not reactive on purpose — RTCPeerConnection instances and their pending-candidate queues are
// mutable engine state, not view state. `peers` above is the reactive projection UI components
// actually read from.
const connections = new Map();
const earlyCandidates = new Map();
const recentlyClosedCallIds = new Set();
const pendingOffers = new Set();
const pendingAnswers = new Set();

let ringTimer = null;
let clockTimer = null;

function createCallId() {
    return crypto.randomUUID();
}

function rememberClosedCallId(id) {
    if (!id) return;
    recentlyClosedCallIds.add(id);
    if (recentlyClosedCallIds.size > 100) {
        recentlyClosedCallIds.delete(recentlyClosedCallIds.values().next().value);
    }
}

async function refreshIceServers() {
    const servers = await useCalls().iceServers();
    if (!Array.isArray(servers)) {
        throw new Error('The call ICE server response was invalid.');
    }

    iceServers = [
        { urls: 'stun:stun.l.google.com:19302' },
        ...servers,
    ];
}

function signalCall(
    targetConversationId,
    payload,
    to = null,
    targetCallId = callId.value,
    targetCallStartedAt = callStartedAt.value,
    targetCallInitiator = callInitiator.value,
) {
    if (!targetCallId) {
        return Promise.reject(new Error('Cannot send call signaling without a call ID.'));
    }

    return useCalls().signal(
        targetConversationId,
        payload,
        to,
        targetCallId,
        targetCallStartedAt,
        targetCallInitiator,
    );
}

function shouldOfferTo(type, id, remoteStartedAt, remoteCallId, remoteInitiator) {
    if (callStartedAt.value !== remoteStartedAt) {
        return callStartedAt.value < remoteStartedAt;
    }

    if (callId.value !== remoteCallId) {
        return callId.value < remoteCallId;
    }

    const store = useChatStore();
    const peerKey = chatableKey(type, id);
    if (callInitiator.value === store.currentKey && remoteInitiator !== store.currentKey) return true;
    if (remoteInitiator === peerKey && callInitiator.value !== peerKey) return false;

    return store.currentKey < peerKey;
}

function reportSignalFailure(error) {
    console.error('[chat] Call signaling failed.', error);
}

export function formatCallClock(totalSeconds) {
    const s = Math.max(0, Math.round(totalSeconds));
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

function otherParticipantOf(conversation) {
    const store = useChatStore();
    const other = (conversation.participants ?? []).find((p) => chatableKeyOf(p) !== store.currentKey);
    return other ? { type: other.chatable_type, id: other.chatable_id } : null;
}

function mediaUnavailable() {
    return !navigator.mediaDevices?.getUserMedia || typeof RTCPeerConnection === 'undefined';
}

function startClock() {
    stopClock();
    clockTimer = setInterval(() => {
        if (startedAt.value) {
            elapsed.value = Math.floor((Date.now() - startedAt.value) / 1000);
        }
    }, 1000);
}

function stopClock() {
    clearInterval(clockTimer);
    clockTimer = null;
}

function syncPeersRef() {
    peers.value = Array.from(connections.values()).map((entry) => ({
        key: chatableKey(entry.type, entry.id),
        type: entry.type,
        id: entry.id,
        stream: entry.stream,
        hasVideo: entry.hasVideo,
    }));
}

function markLive() {
    clearTimeout(ringTimer);
    ringTimer = null;
    if (phase.value !== 'live') {
        phase.value = 'live';
        startedAt.value = startedAt.value ?? Date.now();
        elapsed.value = 0;
        startClock();
    }
}

// A mesh peer connection is always dedicated to exactly one other participant — a group call
// with N people has each client holding N-1 of these, one per remote participant, rather than
// the single shared connection a 1:1 call used to get away with.
function createPeerConnectionFor(
    type,
    id,
    peerCallId = callId.value,
    peerStartedAt = callStartedAt.value,
    peerInitiator = callInitiator.value,
) {
    const key = chatableKey(type, id);
    const pc = new RTCPeerConnection({ iceServers });

    localStream.value?.getAudioTracks().forEach((track) => pc.addTrack(track, localStream.value));
    const outgoingVideoTrack = screenShareStream.value?.getVideoTracks()[0]
        ?? localStream.value?.getVideoTracks()[0];
    if (outgoingVideoTrack) {
        pc.addTrack(outgoingVideoTrack, screenShareStream.value ?? localStream.value);
    } else {
        pc.addTransceiver('video', { direction: 'sendrecv' });
    }

    pc.ontrack = (event) => {
        const entry = connections.get(key);
        if (!entry) return;
        // The stream object is created once, up front, and mutated in place from here on — a UI
        // <video> element only has its `srcObject` assigned when it first mounts (see the `:ref`
        // callback pattern in CallOverlay.vue), so swapping in a *new* MediaStream object later
        // would silently stop showing anything once tracks start arriving.
        entry.stream.addTrack(event.track);
        if (event.track.kind === 'video') {
            const syncVideoState = () => {
                entry.hasVideo = !event.track.muted && event.track.readyState !== 'ended';
                syncPeersRef();
            };
            event.track.addEventListener('mute', syncVideoState);
            event.track.addEventListener('unmute', syncVideoState);
            event.track.addEventListener('ended', syncVideoState);
            syncVideoState();
        }
    };

    pc.onicecandidate = (event) => {
        if (event.candidate && conversationId.value) {
            const entry = connections.get(key);
            const peerCallId = entry?.callId ?? callId.value;
            const peerStartedAt = entry?.callStartedAt ?? callStartedAt.value;
            const peerInitiator = entry?.callInitiator ?? callInitiator.value;
            signalCall(
                conversationId.value,
                { kind: 'ice', candidate: event.candidate.toJSON() },
                { type, id },
                peerCallId,
                peerStartedAt,
                peerInitiator,
            )
                .catch(reportSignalFailure);
        }
    };

    pc.onconnectionstatechange = () => {
        if (pc.connectionState === 'connected') {
            markLive();
        } else if (pc.connectionState === 'closed') {
            removePeer(key);
        }
    };

    connections.set(key, {
        pc,
        type,
        id,
        callId: peerCallId,
        callStartedAt: peerStartedAt,
        callInitiator: peerInitiator,
        stream: new MediaStream(),
        hasVideo: false,
        isOfferer: false,
        iceRestartAttempts: 0,
        iceRestartTimer: null,
        iceRestartInProgress: false,
        negotiating: false,
        pendingCandidates: earlyCandidates.get(`${key}:${peerCallId}`) ?? [],
    });
    pc.oniceconnectionstatechange = () => {
        const entry = connections.get(key);
        if (!entry) return;

        if (pc.iceConnectionState === 'connected' || pc.iceConnectionState === 'completed') {
            clearTimeout(entry.iceRestartTimer);
            entry.iceRestartTimer = null;
            entry.iceRestartAttempts = 0;
        } else if (['disconnected', 'failed'].includes(pc.iceConnectionState) && entry.isOfferer) {
            scheduleIceRestart(key, pc.iceConnectionState === 'failed' ? 0 : ICE_RESTART_DELAY_MS);
        }
    };
    earlyCandidates.delete(`${key}:${peerCallId}`);
    syncPeersRef();
    return pc;
}

function removePeer(key) {
    const entry = connections.get(key);
    for (const candidateKey of earlyCandidates.keys()) {
        if (candidateKey.startsWith(`${key}:`)) earlyCandidates.delete(candidateKey);
    }
    if (!entry) return;
    clearTimeout(entry.iceRestartTimer);
    entry.pc.close();
    connections.delete(key);
    syncPeersRef();
}

function failPeerConnection(key, error) {
    const entry = connections.get(key);
    if (!entry) return;

    console.error('[chat] ICE reconnection attempts were exhausted.', error);
    signalCall(
        conversationId.value,
        { kind: 'leave' },
        { type: entry.type, id: entry.id },
        entry.callId,
        entry.callStartedAt,
        entry.callInitiator,
    ).catch(reportSignalFailure);
    removePeer(key);
    if (!isGroup.value && phase.value !== 'idle') {
        useToast().show('Call connection lost. Check your network and try again.');
        endCall({ silent: true });
    }
}

function scheduleIceRestart(key, delay) {
    const entry = connections.get(key);
    if (!entry || entry.iceRestartTimer || entry.iceRestartInProgress) return;

    entry.iceRestartTimer = setTimeout(() => {
        entry.iceRestartTimer = null;
        restartPeerConnection(key);
    }, delay);
}

async function restartPeerConnection(key) {
    const entry = connections.get(key);
    const hasUnsentOffer = entry?.pc.signalingState === 'have-local-offer'
        && entry.pc.localDescription?.type === 'offer';
    if (
        !entry
        || !entry.isOfferer
        || (!hasUnsentOffer && !['disconnected', 'failed'].includes(entry.pc.iceConnectionState))
        || entry.iceRestartInProgress
    ) {
        return;
    }

    if (entry.iceRestartAttempts >= MAX_ICE_RESTART_ATTEMPTS) {
        failPeerConnection(key);
        return;
    }

    entry.iceRestartInProgress = true;
    entry.iceRestartAttempts += 1;
    let retry = false;

    try {
        await refreshIceServers();
        entry.pc.setConfiguration({ iceServers });
        let offer = entry.pc.localDescription;
        if (!hasUnsentOffer) {
            entry.pc.restartIce();
            offer = await entry.pc.createOffer({ iceRestart: true });
            await entry.pc.setLocalDescription(offer);
        }
        await signalCall(
            conversationId.value,
            { kind: 'offer', sdp: offer.sdp, video: video.value },
            { type: entry.type, id: entry.id },
            entry.callId,
            entry.callStartedAt,
            entry.callInitiator,
        );
    } catch (error) {
        if (entry.iceRestartAttempts >= MAX_ICE_RESTART_ATTEMPTS) {
            failPeerConnection(key, error);
        } else {
            console.warn('[chat] ICE restart attempt failed; retrying.', error);
            retry = true;
        }
    } finally {
        entry.iceRestartInProgress = false;
        if (retry) scheduleIceRestart(key, ICE_RESTART_DELAY_MS);
    }
}

async function flushPendingCandidates(key) {
    const entry = connections.get(key);
    if (!entry) return;
    const queued = entry.pendingCandidates;
    entry.pendingCandidates = [];
    for (const candidate of queued) {
        await entry.pc.addIceCandidate(candidate);
    }
}

function videoSenderFor(pc) {
    return pc.getSenders().find((sender) => sender.track?.kind === 'video')
        ?? pc.getTransceivers().find((transceiver) => transceiver.receiver.track.kind === 'video')?.sender;
}

async function replaceOutgoingVideoTrack(track) {
    const replacedSenders = [];

    try {
        for (const entry of connections.values()) {
            const sender = videoSenderFor(entry.pc);
            if (!sender) {
                throw new Error('The call connection has no negotiated video sender.');
            }
            await sender.replaceTrack(track);
            replacedSenders.push(sender);
        }
    } catch (error) {
        const cameraTrack = localStream.value?.getVideoTracks()[0] ?? null;
        await Promise.all(replacedSenders.map((sender) => sender.replaceTrack(cameraTrack).catch((restoreError) => {
            console.error('[chat] Could not restore a call video sender after screen-share setup failed.', restoreError);
        })));
        throw error;
    }
}

async function stopScreenShare() {
    const stream = screenShareStream.value;
    if (!stream) return;

    screenShareStream.value = null;
    isScreenSharing.value = false;

    try {
        await replaceOutgoingVideoTrack(localStream.value?.getVideoTracks()[0] ?? null);
    } catch (error) {
        console.error('[chat] Could not restore the call video track after screen sharing.', error);
        useToast().show('Could not stop screen sharing cleanly.');
    } finally {
        stream.getTracks().forEach((track) => track.stop());
    }
}

async function toggleScreenShare() {
    if (isScreenSharing.value) {
        await stopScreenShare();
        return;
    }

    if (phase.value === 'idle' || !conversationId.value) return;

    if (!navigator.mediaDevices?.getDisplayMedia) {
        useToast().show('Screen sharing is not supported in this browser.');
        return;
    }

    let stream;
    try {
        stream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
    } catch (error) {
        if (error.name !== 'NotAllowedError') {
            console.error('[chat] Could not capture the screen for sharing.', error);
            useToast().show('Could not start screen sharing.');
        }
        return;
    }

    if (phase.value === 'idle' || !conversationId.value) {
        stream.getTracks().forEach((mediaTrack) => mediaTrack.stop());
        return;
    }

    const track = stream.getVideoTracks()[0];
    if (!track) {
        stream.getTracks().forEach((mediaTrack) => mediaTrack.stop());
        useToast().show('No screen video track was available to share.');
        return;
    }

    try {
        await replaceOutgoingVideoTrack(track);
    } catch (error) {
        stream.getTracks().forEach((mediaTrack) => mediaTrack.stop());
        console.error('[chat] Could not add screen sharing to the call.', error);
        useToast().show('Could not start screen sharing.');
        return;
    }

    screenShareStream.value = stream;
    isScreenSharing.value = true;
    track.addEventListener('ended', stopScreenShare, { once: true });
}

// Offers only ever flow from an already-connected participant toward a newly-announced one —
// the joiner itself never initiates an offer, only answers ones addressed to it. That one-way
// rule is what keeps a full mesh glare-free without any extra negotiation protocol.
async function offerTo(
    type,
    id,
    peerCallId = callId.value,
    peerStartedAt = callStartedAt.value,
    peerInitiator = callInitiator.value,
) {
    const key = chatableKey(type, id);
    if (connections.has(key) || pendingOffers.has(key)) return;
    pendingOffers.add(key);

    const negotiationCallId = peerCallId;
    const sessionCallId = callId.value;
    const negotiationConversationId = conversationId.value;
    let entry = null;
    try {
        await refreshIceServers();
        if (callId.value !== sessionCallId || conversationId.value !== negotiationConversationId || phase.value === 'idle') return;

        const pc = createPeerConnectionFor(type, id, peerCallId, peerStartedAt, peerInitiator);
        entry = connections.get(key);
        entry.isOfferer = true;
        entry.negotiating = true;
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        if (callId.value !== sessionCallId || conversationId.value !== negotiationConversationId) return;
        await signalCall(
            negotiationConversationId,
            { kind: 'offer', sdp: offer.sdp, video: video.value },
            { type, id },
            negotiationCallId,
            peerStartedAt,
            peerInitiator,
        );
    } finally {
        if (entry) entry.negotiating = false;
        pendingOffers.delete(key);
    }
}

async function answerTo(type, id, sdp, peerCallId = callId.value, peerStartedAt = callStartedAt.value, peerInitiator = callInitiator.value) {
    const key = chatableKey(type, id);
    const answerKey = `${key}:${peerCallId}`;
    if (pendingAnswers.has(answerKey)) return;
    pendingAnswers.add(answerKey);

    const negotiationCallId = peerCallId;
    const sessionCallId = callId.value;
    const negotiationConversationId = conversationId.value;
    let entry = null;
    try {
        await refreshIceServers();
        if (callId.value !== sessionCallId || conversationId.value !== negotiationConversationId || phase.value === 'idle') return;

        const existing = connections.get(key);
        if (existing && existing.callId !== peerCallId) return;
        if (existing && (existing.pc.signalingState !== 'stable' || existing.negotiating)) return;
        const pc = existing?.pc ?? createPeerConnectionFor(type, id, peerCallId, peerStartedAt, peerInitiator);
        entry = connections.get(key);
        entry.negotiating = true;
        await pc.setRemoteDescription({ type: 'offer', sdp });
        await flushPendingCandidates(key);
        const answer = await pc.createAnswer();
        await pc.setLocalDescription(answer);
        if (callId.value !== sessionCallId || conversationId.value !== negotiationConversationId) return;
        await signalCall(
            negotiationConversationId,
            { kind: 'answer', sdp: answer.sdp },
            { type, id },
            negotiationCallId,
            peerStartedAt,
            peerInitiator,
        );
        if (existing) {
            existing.iceRestartAttempts = 0;
        }
    } finally {
        if (entry) entry.negotiating = false;
        pendingAnswers.delete(answerKey);
    }
}

function cleanup() {
    rememberClosedCallId(callId.value);
    clearTimeout(ringTimer);
    ringTimer = null;
    stopClock();

    localStream.value?.getTracks().forEach((t) => t.stop());
    localStream.value = null;
    screenShareStream.value?.getTracks().forEach((t) => t.stop());
    screenShareStream.value = null;
    isScreenSharing.value = false;

    connections.forEach((entry) => entry.pc.close());
    connections.clear();
    earlyCandidates.clear();
    peers.value = [];

    conversationId.value = null;
    callId.value = null;
    callStartedAt.value = null;
    callInitiator.value = null;
    isGroup.value = false;
    announcer.value = null;
    video.value = false;
    muted.value = false;
    camOn.value = false;
    speakerOn.value = true;
    minimized.value = false;
    startedAt.value = null;
    elapsed.value = 0;
}

export function useCall() {
    async function startCall(conversation, { video: withVideo = false } = {}) {
        if (!callsEnabled) {
            useToast().show('Calling is disabled by the host application.');
            return;
        }

        if (phase.value !== 'idle') {
            return;
        }

        if (mediaUnavailable()) {
            useToast().show('Calling isn’t supported in this browser.');
            return;
        }

        conversationId.value = conversation.id;
        callId.value = createCallId();
        callStartedAt.value = Date.now();
        callInitiator.value = useChatStore().currentKey;
        isGroup.value = conversation.type === 'group';
        announcer.value = isGroup.value ? null : otherParticipantOf(conversation);
        video.value = withVideo;
        camOn.value = withVideo;
        phase.value = 'outgoing';

        try {
            await refreshIceServers();
        } catch (error) {
            console.error('[chat] Could not load call network configuration.', error);
            useToast().show('Could not prepare the call connection. Please try again.');
            cleanup();
            phase.value = 'idle';
            return;
        }

        try {
            localStream.value = await navigator.mediaDevices.getUserMedia({ audio: true, video: withVideo });
        } catch (error) {
            console.error('[chat] Could not access call media devices.', error);
            useToast().show('Could not access microphone/camera.');
            cleanup();
            phase.value = 'idle';
            return;
        }

        if (phase.value !== 'outgoing' || !conversationId.value || !callId.value) {
            cleanup();
            phase.value = 'idle';
            return;
        }

        try {
            await signalCall(conversationId.value, { kind: 'join', video: withVideo });
        } catch (error) {
            reportSignalFailure(error);
            useToast().show('Could not start the call. Check your connection and try again.');
            cleanup();
            phase.value = 'idle';
            return;
        }

        if (isGroup.value) {
            // A group call is an open room, not a ring-one-person-and-wait affair — it's "live"
            // (from this caller's perspective) the moment it's announced, even with nobody else
            // in it yet, and stays open rather than auto-cancelling after a silent ring window.
            markLive();
        } else {
            ringTimer = setTimeout(() => {
                if (phase.value === 'outgoing') {
                    useToast().show('No answer.');
                    if (announcer.value && conversationId.value) {
                        signalCall(conversationId.value, { kind: 'cancel' }, announcer.value).catch(reportSignalFailure);
                    }
                    endCall({ silent: true });
                }
            }, RING_TIMEOUT_MS);
        }
    }

    async function acceptCall() {
        if (phase.value !== 'incoming') {
            return;
        }

        try {
            await refreshIceServers();
        } catch (error) {
            console.error('[chat] Could not load call network configuration.', error);
            useToast().show('Could not prepare the call connection. Please try again.');
            return;
        }

        try {
            localStream.value = await navigator.mediaDevices.getUserMedia({ audio: true, video: video.value });
        } catch (error) {
            console.error('[chat] Could not access call media devices.', error);
            useToast().show('Could not access microphone/camera.');
            declineCall();
            return;
        }

        if (phase.value !== 'incoming' || !conversationId.value || !callId.value) {
            localStream.value?.getTracks().forEach((track) => track.stop());
            localStream.value = null;
            return;
        }

        camOn.value = video.value;
        markLive();
        try {
            await signalCall(conversationId.value, { kind: 'join', video: video.value });
        } catch (error) {
            reportSignalFailure(error);
            useToast().show('Could not answer the call. Check your connection and try again.');
            cleanup();
            phase.value = 'idle';
        }
    }

    function declineCall() {
        if (phase.value !== 'incoming') {
            return;
        }
        if (announcer.value) {
            signalCall(conversationId.value, { kind: 'decline' }, announcer.value).catch(reportSignalFailure);
        }
        cleanup();
        phase.value = 'idle';
    }

    function endCall({ silent = false, skipLog = false } = {}) {
        const wasLive = phase.value === 'live';
        const wasOutgoing = phase.value === 'outgoing' || phase.value === 'connecting';
        const finalElapsed = elapsed.value;
        // Captured before cleanup() wipes them.
        const finalConversationId = conversationId.value;
        const finalCallId = callId.value;
        const finalIsGroup = isGroup.value;
        const finalVideo = video.value;
        const finalParticipants = peers.value.map((p) => ({ type: p.type, id: p.id }));

        if (finalConversationId && finalCallId && phase.value !== 'idle' && !silent) {
            if (wasOutgoing && announcer.value) {
                signalCall(finalConversationId, { kind: 'cancel' }, announcer.value, finalCallId)
                    .catch(reportSignalFailure);
            } else {
                signalCall(finalConversationId, { kind: 'leave' }, null, finalCallId)
                    .catch(reportSignalFailure);
                if (finalIsGroup) {
                    connections.forEach((entry) => {
                        if (entry.callId !== finalCallId) {
                            signalCall(
                                finalConversationId,
                                { kind: 'leave' },
                                { type: entry.type, id: entry.id },
                                entry.callId,
                                entry.callStartedAt,
                                entry.callInitiator,
                            )
                                .catch(reportSignalFailure);
                        }
                    });
                }
            }
        }

        cleanup();
        phase.value = 'idle';

        // Log any call that reached the outgoing/live phase — including one nobody answered (0
        // duration, 0 participants) — not just ones that connected. A call that never got that far
        // (e.g. declined while still incoming) never sets wasLive/wasOutgoing, so it's excluded
        // automatically.
        if (!skipLog && (wasLive || wasOutgoing) && finalConversationId) {
            useMessages().send(finalConversationId, {
                type: 'call',
                metadata: {
                    video: finalVideo,
                    duration_seconds: Math.round(finalElapsed),
                    participants: finalParticipants,
                },
            }).catch((error) => {
                console.error('[chat] Could not save the call summary.', error);
            });
        }

        if (wasLive) {
            useToast().show(`Call ended · ${formatCallClock(finalElapsed)}`);
        } else if (wasOutgoing && !silent) {
            useToast().show('Call cancelled.');
        }
    }

    function toggleMute() {
        muted.value = !muted.value;
        localStream.value?.getAudioTracks().forEach((t) => (t.enabled = !muted.value));
    }

    function toggleCam() {
        if (!video.value) return;
        camOn.value = !camOn.value;
        localStream.value?.getVideoTracks().forEach((t) => (t.enabled = camOn.value));
    }

    function toggleSpeaker() {
        speakerOn.value = !speakerOn.value;
    }

    function minimize() {
        minimized.value = true;
    }

    function restore() {
        minimized.value = false;
    }

    async function handleSignal({ conversation_id: incomingConversationId, from_type, from_id, payload }) {
        if (!callsEnabled) return;

        const fromKey = chatableKey(from_type, from_id);

        if (
            !payload?.call_id
            || !payload?.kind
            || !Number.isInteger(payload.call_started_at)
            || typeof payload.call_initiator !== 'string'
        ) {
            console.warn('[chat] Ignoring a malformed call signal.');
            return;
        }

        try {
            switch (payload.kind) {
                case 'join': {
                    if (phase.value === 'idle') {
                        if (recentlyClosedCallIds.has(payload.call_id)) return;
                        const store = useChatStore();
                        conversationId.value = incomingConversationId;
                        callId.value = payload.call_id;
                        callStartedAt.value = payload.call_started_at;
                        callInitiator.value = payload.call_initiator;
                        isGroup.value = store.conversations.find((c) => c.id === incomingConversationId)?.type === 'group';
                        announcer.value = { type: from_type, id: from_id };
                        video.value = !!payload.video;
                        phase.value = 'incoming';
                        ringTimer = setTimeout(() => {
                            if (phase.value === 'incoming' && callId.value === payload.call_id) {
                                useToast().show('Missed call.');
                                cleanup();
                                phase.value = 'idle';
                            }
                        }, RING_TIMEOUT_MS);
                        return;
                    }

                    const canJoinActiveGroup = isGroup.value
                        && !!localStream.value
                        && ['outgoing', 'live'].includes(phase.value);
                    if (
                        conversationId.value !== incomingConversationId
                        || (callId.value !== payload.call_id && !canJoinActiveGroup)
                    ) {
                        await signalCall(
                            incomingConversationId,
                            { kind: 'busy' },
                            { type: from_type, id: from_id },
                            payload.call_id,
                            payload.call_started_at,
                            payload.call_initiator,
                        );
                        return;
                    }

                    if (fromKey === useChatStore().currentKey || connections.has(fromKey)) {
                        return;
                    }
                    if (!localStream.value) return;
                    if (!shouldOfferTo(from_type, from_id, payload.call_started_at, payload.call_id, payload.call_initiator)) {
                        return;
                    }

                    markLive();
                    await offerTo(from_type, from_id);
                    return;
                }
                case 'offer': {
                    const entry = connections.get(fromKey);
                    const isCurrentCallOffer = callId.value === payload.call_id;
                    if (
                        conversationId.value !== incomingConversationId
                        || !localStream.value
                        || (!isCurrentCallOffer && (!isGroup.value || phase.value !== 'live'))
                        || (entry && entry.callId !== payload.call_id)
                    ) {
                        return;
                    }
                    await answerTo(
                        from_type,
                        from_id,
                        payload.sdp,
                        payload.call_id,
                        payload.call_started_at,
                        payload.call_initiator,
                    );
                    return;
                }
                case 'answer': {
                    if (conversationId.value !== incomingConversationId) return;
                    const entry = connections.get(fromKey);
                    if (entry && entry.callId === payload.call_id && entry.pc.signalingState === 'have-local-offer') {
                        await entry.pc.setRemoteDescription({ type: 'answer', sdp: payload.sdp });
                        await flushPendingCandidates(fromKey);
                    }
                    return;
                }
                case 'ice': {
                    if (conversationId.value !== incomingConversationId) return;
                    const entry = connections.get(fromKey);
                    if (entry && entry.callId !== payload.call_id) return;
                    const candidateQueueKey = `${fromKey}:${payload.call_id}`;
                    if (!entry) {
                        const queued = earlyCandidates.get(candidateQueueKey) ?? [];
                        if (!earlyCandidates.has(candidateQueueKey) && earlyCandidates.size >= 64) {
                            console.warn('[chat] Dropping early call ICE candidates from too many peers.');
                            return;
                        }
                        if (queued.length >= MAX_QUEUED_ICE_CANDIDATES) {
                            console.warn('[chat] Dropping excess early call ICE candidates.');
                            return;
                        }
                        queued.push(payload.candidate);
                        earlyCandidates.set(candidateQueueKey, queued);
                        return;
                    }
                    if (entry.pc.remoteDescription) {
                        await entry.pc.addIceCandidate(payload.candidate);
                    } else if (entry.pendingCandidates.length < MAX_QUEUED_ICE_CANDIDATES) {
                        entry.pendingCandidates.push(payload.candidate);
                    } else {
                        console.warn('[chat] Dropping excess pending call ICE candidates.');
                    }
                    return;
                }
                case 'decline':
                case 'busy': {
                    if (
                        phase.value === 'outgoing'
                        && !isGroup.value
                        && conversationId.value === incomingConversationId
                        && callId.value === payload.call_id
                    ) {
                        useToast().show(payload.kind === 'busy' ? 'Line busy.' : 'Call declined.');
                        endCall({ silent: true });
                    }
                    return;
                }
                case 'cancel': {
                    if (
                        phase.value !== 'idle'
                        && conversationId.value === incomingConversationId
                        && callId.value === payload.call_id
                        && announcer.value
                        && chatableKey(announcer.value.type, announcer.value.id) === fromKey
                    ) {
                        cleanup();
                        phase.value = 'idle';
                    }
                    return;
                }
                case 'leave': {
                    if (conversationId.value !== incomingConversationId) return;

                    const entry = connections.get(fromKey);
                    if (entry?.callId === payload.call_id) {
                        removePeer(fromKey);
                        if (!isGroup.value && peers.value.length === 0 && phase.value === 'live') {
                            endCall({ silent: true, skipLog: true });
                        }
                        return;
                    }

                    if (
                        !isGroup.value
                        && phase.value !== 'idle'
                        && callId.value === payload.call_id
                        && announcer.value
                        && chatableKey(announcer.value.type, announcer.value.id) === fromKey
                    ) {
                        useToast().show('The other person ended the call.');
                        endCall({ silent: true, skipLog: true });
                        return;
                    }

                    if (
                        phase.value === 'incoming'
                        && announcer.value
                        && callId.value === payload.call_id
                        && chatableKey(announcer.value.type, announcer.value.id) === fromKey
                    ) {
                        useToast().show('Missed call.');
                        cleanup();
                        phase.value = 'idle';
                    }
                    return;
                }
            }
        } catch (error) {
            console.error('[chat] Call negotiation failed.', error);
            const entry = connections.get(fromKey);
            const belongsToCurrentCall = callId.value === payload.call_id
                || (isGroup.value && (entry?.callId === payload.call_id || ['join', 'offer'].includes(payload.kind)));
            if (conversationId.value === incomingConversationId && belongsToCurrentCall) {
                if (entry?.callId === payload.call_id) removePeer(fromKey);
                useToast().show('Could not connect the call. Check your network and try again.');
                if (!isGroup.value) {
                    endCall({ silent: true });
                }
            }
        }
    }

    return {
        phase,
        conversationId,
        isGroupCall: computed(() => isGroup.value),
        announcer,
        peers,
        video,
        muted,
        camOn,
        speakerOn,
        minimized,
        elapsed,
        localStream,
        screenShareStream,
        isScreenSharing,
        isActive: computed(() => phase.value !== 'idle'),
        startCall,
        acceptCall,
        declineCall,
        endCall,
        toggleMute,
        toggleCam,
        toggleSpeaker,
        toggleScreenShare,
        minimize,
        restore,
        handleSignal,
    };
}
