<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The single page every contestant loads first.
 *
 * The whole exam lives behind one Blade view that boots the Vue app, so if
 * this route stops responding there is no contestant flow at all - no login,
 * no question, no result. It is worth one assertion.
 */
class EntryPointTest extends TestCase
{
    public function test_the_entry_page_responds(): void
    {
        /*
         * Vite is stubbed out.
         *
         * The view calls @vite, which reads public/build/manifest.json - a
         * build artefact, and correctly gitignored. Leaving it in would make
         * this test assert "somebody ran npm run build on this machine", which
         * passes on a developer's laptop and fails on a fresh checkout. The
         * bundle is the frontend job's business; this test is about routing.
         */
        $this->withoutVite();

        $this->get('/')->assertOk();
    }
}
