import { useApi } from './useApi';

let cachedIceServers = null;
let iceServersExpireAt = 0;

export function useCalls() {
    const api = useApi();

    async function iceServers() {
        if (cachedIceServers && Date.now() < iceServersExpireAt - 60000) {
            return cachedIceServers;
        }

        const { data } = await api.get('/calls/ice-servers');
        if (!Array.isArray(data.ice_servers)) {
            throw new Error('The server returned invalid ICE server configuration.');
        }
        cachedIceServers = data.ice_servers;
        iceServersExpireAt = data.expires_at
            ? data.expires_at * 1000
            : Date.now() + 300000;

        return data.ice_servers;
    }

    async function signal(conversationId, payload, to = null, callId, callStartedAt, callInitiator) {
        await api.post(`/conversations/${conversationId}/call/signal`, {
            payload: {
                ...payload,
                call_id: callId,
                call_started_at: callStartedAt,
                call_initiator: callInitiator,
            },
            ...(to ? { to_type: to.type, to_id: to.id } : {}),
        });
    }

    return { iceServers, signal };
}
