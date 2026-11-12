<?php

namespace App\Services\Website\Apps;

use App\Models\User;
use App\Models\Website;

/**
 * One app in the catalog (backlog U8). AppInstallService runs the shared flow
 * (checks, backup, database, permissions, document root) and calls these
 * hooks in order: plan → preflight → download → afterDatabase → configure →
 * complete. Hooks throw AppInstallFailed to stop with a message.
 */
interface AppInstaller
{
    public function key(): string;

    /**
     * Page metadata: label, description, document_root, database
     * ("required", "optional" or "new"), engines, upload, fields (the
     * account fields the page asks for).
     *
     * @return array<string, mixed>
     */
    public function definition(): array;

    /**
     * Installable versions: value, label, min_php, max_php.
     *
     * @return array<int, array{value: string, label: string, min_php: string, max_php: string}>
     *
     * @throws AppInstallFailed when the version list cannot be loaded
     */
    public function versions(): array;

    /**
     * Request rules for this app's own fields.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array;

    /**
     * Resolve the chosen version before anything is touched.
     *
     * @param  array<string, mixed>  $input
     * @return array{range: array{min_php: string, max_php: string}, label: string, package: mixed}
     *
     * @throws AppInstallFailed
     */
    public function plan(array $input, Website $website, ?User $actor): array;

    /** PHP extension the app talks to PostgreSQL through. */
    public function postgresModule(): string;

    /** Checks run as the site owner before the site is touched. */
    public function preflight(AppInstallContext $context): void;

    /** @return array{success: bool, output: string} */
    public function download(AppInstallContext $context): array;

    public function afterDatabase(AppInstallContext $context): void;

    public function configure(AppInstallContext $context): void;

    /** Runs after finalize; returns text appended to the success message. */
    public function complete(AppInstallContext $context): string;
}
