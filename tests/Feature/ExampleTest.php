<?php

it('sends a visitor from the root to the login screen', function () {
    // '/' is a redirect, not a page: a guest goes to the one login screen.
    $this->get('/')->assertRedirect(route('login', absolute: false));
});
