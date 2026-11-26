<?php
/**
 * Premium-only bootstrap — Freemius preprocessor strips from free builds.
 *
 * @package Pryviko
 */

declare(strict_types=1);

namespace Pryviko\QuickOrder\Pro;

final class ProBootstrap__premium_only
{
    public static function boot(): void
    {
        CsvUploadController__premium_only::register();
        SavedListsController__premium_only::register();
        OrderNotesHandler__premium_only::register();
        SampleCsvController__premium_only::register();
    }
}
