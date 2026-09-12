<?php

namespace App\Services\SystemEmails;

use Illuminate\View\View;

class EmailViewComposer
{
    public function compose(View $view): void
    {
        if (! str_contains(substr($view->name(), strlen('emails.')), '.') && ! array_key_exists('emailAtelier', $view->getData())) {
            $view->with('emailAtelier', app(EmailArtwork::class)->forView($view->name(), $view->getData()));
        }
    }
}
