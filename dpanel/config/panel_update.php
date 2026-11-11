<?php

/*
 * Backlog U6: updating dPanel from the panel. installer.sh records what it
 * installed in .env (APP_VERSION, DPANEL_RELEASE_REF, DPANEL_RELEASE_COMMIT);
 * new releases are the version tags of the GitHub repository it installs from.
 */
return [
    'repo' => env('DPANEL_REPO', 'mdsazzad0002/dpanel'),
    'release_ref' => env('DPANEL_RELEASE_REF', ''),
    'release_commit' => env('DPANEL_RELEASE_COMMIT', ''),
    'github_api' => rtrim((string) env('DPANEL_GITHUB_API', 'https://api.github.com'), '/'),
    // Minutes a release check is kept before GitHub is asked again.
    'check_cache_minutes' => (int) env('DPANEL_UPDATE_CHECK_MINUTES', 30),
];
