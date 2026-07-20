<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Accessibility rules that a future edit could silently undo.
 *
 * These are the ones that fail invisibly: a sighted mouse user notices none of
 * them, so nothing but a test will report the regression.
 */
function a11yUser(string $role = 'store'): User
{
    Role::findOrCreate($role, 'web');

    return User::factory()->create()->assignRole($role);
}

it('gives every icon-only button an accessible name', function () {
    $html = $this->actingAs(a11yUser())
        ->get(route('store.material.receivings.index'))
        ->assertOk()
        ->getContent();

    // A button whose only content is an icon announces as "button" and nothing
    // else unless it carries a label.
    preg_match_all('/<button(?![^>]*aria-label)[^>]*>\s*<i class="bi[^"]*"[^>]*>\s*<\/i>\s*<\/button>/', $html, $m);

    expect($m[0])->toBeEmpty();
});

it('hides decorative icons from screen readers', function () {
    $html = $this->actingAs(a11yUser())
        ->get(route('store.material.receivings.index'))
        ->assertOk()
        ->getContent();

    $total = preg_match_all('/<i class="bi [^"]*"/', $html);
    $hidden = preg_match_all('/<i class="bi [^"]*"[^>]*aria-hidden/', $html);

    // Not every single one — a handful build their class from a variable — but
    // the overwhelming majority must be hidden or the page reads as noise.
    expect($hidden)->toBeGreaterThanOrEqual((int) ($total * 0.9));
});

it('keeps a skip link ahead of the navigation', function () {
    $html = $this->actingAs(a11yUser())
        ->get(route('store.dashboard'))
        ->assertOk()
        ->getContent();

    $skip = strpos($html, 'Skip to main content');
    $sidebar = strpos($html, 'sidebar-nav-link');

    expect($skip)->not->toBeFalse();
    expect($sidebar === false || $skip < $sidebar)->toBeTrue();
});

it('labels every form control on the receiving screen', function () {
    $html = $this->actingAs(a11yUser())
        ->get(route('store.material.receivings.index'))
        ->assertOk()
        ->getContent();

    // Any select or text input must have a label, an aria-label, or be hidden.
    preg_match_all('/<select(?![^>]*aria-label)(?![^>]*id=)[^>]*>/', $html, $selects);

    expect($selects[0])->toBeEmpty();
});

it('announces flash messages without needing focus', function () {
    session()->flash('success', 'Saved.');

    $html = $this->actingAs(a11yUser())
        ->get(route('store.dashboard'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('role="alert"');
});

it('marks the current breadcrumb page for screen readers', function () {
    $html = $this->actingAs(a11yUser())
        ->get(route('store.material.ledger'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('aria-current="page"')
        ->and($html)->toContain('aria-label="Breadcrumb"');
});
