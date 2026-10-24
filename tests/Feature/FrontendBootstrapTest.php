<?php

use Chat\Tests\Fixtures\User;

function frontendUser(string $email): User
{
    return User::query()->create(['name' => $email, 'email' => $email, 'password' => bcrypt('secret')]);
}

it('serves the built js and css assets with correct content types', function () {
    $this->get('/chat/assets/app.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');

    $this->get('/chat/assets/app.css')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/css; charset=UTF-8');
});

it('renders the mount page with a ChatConfig script tag when reverb is configured', function () {
    config(['broadcasting.connections.reverb' => [
        'driver' => 'reverb',
        'key' => 'test-key',
        'options' => ['host' => '127.0.0.1', 'port' => 8080, 'scheme' => 'http'],
    ]]);

    $user = frontendUser('frontend-page@example.com');

    $response = $this->actingAs($user)->get('/chat');

    $response->assertOk();
    $response->assertSee('chat-app', false);
    $response->assertSee('window.ChatConfig', false);
    $response->assertSee('test-key', false);
});

it('still renders the mount page when no reverb broadcasting connection is configured', function () {
    config(['broadcasting.connections.reverb' => null]);

    $user = frontendUser('frontend-noreverb@example.com');

    $response = $this->actingAs($user)->get('/chat');

    $response->assertOk();
    $response->assertSee('chat-app', false);
});

it('inherits host fonts by default and uses the standalone fallback font', function () {
    $user = frontendUser('frontend-fonts@example.com');

    $html = $this->actingAs($user)->get('/chat')
        ->assertOk()
        ->assertSee('<body class="chat-page">', false)
        ->assertSee('fonts.googleapis.com/css2?family=Figtree', false)
        ->assertDontSee('Caprasimo', false)
        ->getContent();

    $this->get('/chat/assets/app.css')->assertOk();
    $css = file_get_contents(dirname(__DIR__, 2).'/resources/dist/app.css');

    expect(str_contains($html, 'class="chat-page"'))->toBeTrue()
        ->and($css)->toBeString()
        ->and(str_contains($css, '--chat-font-sans: inherit;'))->toBeTrue()
        ->and(str_contains($css, '--chat-font-display: inherit;'))->toBeTrue()
        ->and(str_contains($css, 'body.chat-page{font-family:Figtree,system-ui,ui-sans-serif,sans-serif}'))->toBeTrue();
});

it('applies the theme synchronously before the deferred app.js bundle to avoid a flash of the wrong theme', function () {
    $user = frontendUser('frontend-theme-sync@example.com');

    $html = $this->actingAs($user)->get('/chat')->assertOk()->getContent();

    $mountPosition = strpos($html, 'id="chat-app"');
    $themeScriptPosition = strpos($html, "localStorage.getItem('chat:theme')");
    $deferredBundlePosition = strpos($html, ' defer>');

    expect($mountPosition)->not->toBeFalse()
        ->and($themeScriptPosition)->not->toBeFalse()
        ->and($deferredBundlePosition)->not->toBeFalse()
        ->and($themeScriptPosition)->toBeGreaterThan($mountPosition)
        ->and($themeScriptPosition)->toBeLessThan($deferredBundlePosition);
});
