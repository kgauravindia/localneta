<?php
// includes/vcs.php - Secure Git Version Control & GitHub Update Helper for LocalNeta

if (!defined('APP_INIT') && !defined('PPMS_INIT')) {
    http_response_code(403);
    exit('Forbidden');
}

function localneta_repo_root(): string {
    $root = realpath(__DIR__ . '/..');
    return $root !== false ? $root : dirname(__DIR__);
}

function localneta_app_version(): array {
    static $meta = null;
    if ($meta !== null) {
        return $meta;
    }

    $defaults = [
        'name' => 'LocalNeta',
        'version' => '1.2.0',
        'codename' => 'Tiranga Civic & PPMS',
        'released_at' => '2026-11-11',
        'repository' => defined('LOCALNETA_GITHUB_REPO')
            ? (string)LOCALNETA_GITHUB_REPO
            : 'https://github.com/kgauravindia/localneta.git',
        'branch' => defined('LOCALNETA_GITHUB_BRANCH')
            ? (string)LOCALNETA_GITHUB_BRANCH
            : 'main',
        'highlights' => [],
    ];

    $versionFile = localneta_repo_root() . DIRECTORY_SEPARATOR . 'version.json';
    if (is_file($versionFile)) {
        $raw = @file_get_contents($versionFile);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $meta = array_merge($defaults, $decoded);
            if (defined('LOCALNETA_GITHUB_REPO') && (string)LOCALNETA_GITHUB_REPO !== '') {
                $meta['repository'] = (string)LOCALNETA_GITHUB_REPO;
            }
            if (defined('LOCALNETA_GITHUB_BRANCH') && (string)LOCALNETA_GITHUB_BRANCH !== '') {
                $meta['branch'] = (string)LOCALNETA_GITHUB_BRANCH;
            }
            return $meta;
        }
    }

    $meta = $defaults;
    return $meta;
}

function localneta_github_repo_url(): string {
    $meta = localneta_app_version();
    $url = trim((string)($meta['repository'] ?? 'https://github.com/kgauravindia/localneta.git'));
    if (preg_match('#^https://github\.com/[A-Za-z0-9._-]+/[A-Za-z0-9._-]+(?:\.git)?$#', $url)) {
        return $url;
    }
    return 'https://github.com/kgauravindia/localneta.git';
}

function localneta_github_branch(): string {
    $meta = localneta_app_version();
    $branch = trim((string)($meta['branch'] ?? 'main'));
    if (preg_match('/^[A-Za-z0-9._\/-]{1,80}$/', $branch) && !str_contains($branch, '..')) {
        return $branch;
    }
    return 'main';
}

function localneta_sanitize_git_text(string $text): string {
    return (string)preg_replace('#(https?://)[^/@\s]+@#i', '$1***@', $text);
}

function localneta_find_git_binary(): ?string {
    static $resolved = false;
    static $cached = null;
    if ($resolved) {
        return $cached;
    }
    $resolved = true;

    $candidates = [];
    $envBin = trim((string)getenv('LOCALNETA_GIT_BIN'));
    if ($envBin !== '') {
        $candidates[] = $envBin;
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        $candidates = array_merge($candidates, [
            'C:\\Program Files\\Git\\cmd\\git.exe',
            'C:\\Program Files\\Git\\bin\\git.exe',
            'C:\\Program Files (x86)\\Git\\cmd\\git.exe',
            'D:\\Program Files\\Git\\cmd\\git.exe',
            'C:\\Laragon\\bin\\git\\cmd\\git.exe',
            'D:\\laragon\\bin\\git\\cmd\\git.exe',
        ]);
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $cached = $path;
                return $cached;
            }
        }
        $whereOut = @shell_exec('where git 2>NUL');
        if (is_string($whereOut)) {
            foreach (preg_split('/\r?\n/', trim($whereOut)) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && is_file($line)) {
                    $cached = $line;
                    return $cached;
                }
            }
        }
    } else {
        $candidates = array_merge($candidates, [
            '/usr/bin/git',
            '/usr/local/bin/git',
            '/bin/git',
        ]);
        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                $cached = $path;
                return $cached;
            }
        }
        $whichOut = @shell_exec('command -v git 2>/dev/null');
        $whichLine = is_string($whichOut) ? trim($whichOut) : '';
        if ($whichLine !== '' && is_file($whichLine)) {
            $cached = $whichLine;
            return $cached;
        }
    }

    return null;
}

function localneta_vcs_build_env(): array {
    $env = [];
    $procEnv = getenv();
    if (is_array($procEnv)) {
        foreach ($procEnv as $k => $v) {
            if (is_string($k) && $k !== '' && is_scalar($v)) {
                $env[$k] = (string)$v;
            }
        }
    }
    foreach (($_SERVER ?? []) as $k => $v) {
        if (is_string($k) && $k !== '' && is_scalar($v) && !isset($env[$k])) {
            $env[$k] = (string)$v;
        }
    }
    $env['GIT_TERMINAL_PROMPT'] = '0';
    $env['GCM_INTERACTIVE'] = 'never';
    $env['LC_ALL'] = 'C';
    return $env;
}

function localneta_git_exec(array $args, int $timeoutSec = 25): array {
    $gitBin = localneta_find_git_binary();
    if ($gitBin === null) {
        return [
            'ok' => false,
            'exit_code' => -1,
            'stdout' => '',
            'stderr' => 'Git executable not found on server PATH.',
            'command' => 'git ' . implode(' ', $args),
        ];
    }
    if (!function_exists('proc_open')) {
        return [
            'ok' => false,
            'exit_code' => -1,
            'stdout' => '',
            'stderr' => 'PHP proc_open() is disabled in php.ini on this server.',
            'command' => 'git ' . implode(' ', $args),
        ];
    }

    $repoRoot = localneta_repo_root();
    $cmd = array_merge([$gitBin, '-c', 'safe.directory=*', '-C', $repoRoot], $args);
    $desc = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $proc = @proc_open($cmd, $desc, $pipes, $repoRoot, localneta_vcs_build_env());
    if (!is_resource($proc)) {
        return [
            'ok' => false,
            'exit_code' => -1,
            'stdout' => '',
            'stderr' => 'Failed to start git process.',
            'command' => 'git ' . implode(' ', $args),
        ];
    }

    @fclose($pipes[0]);
    @stream_set_blocking($pipes[1], false);
    @stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + max(3, $timeoutSec);
    $timedOut = false;

    while (true) {
        $stdout .= (string)@stream_get_contents($pipes[1]);
        $stderr .= (string)@stream_get_contents($pipes[2]);

        $status = @proc_get_status($proc);
        if (!$status || empty($status['running'])) {
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            @proc_terminate($proc);
            break;
        }
        usleep(40000);
    }

    $stdout .= (string)@stream_get_contents($pipes[1]);
    $stderr .= (string)@stream_get_contents($pipes[2]);
    @fclose($pipes[1]);
    @fclose($pipes[2]);

    $exitCode = @proc_close($proc);
    if ($timedOut) {
        return [
            'ok' => false,
            'exit_code' => 124,
            'stdout' => localneta_sanitize_git_text(trim($stdout)),
            'stderr' => 'Git command timed out after ' . $timeoutSec . 's.',
            'command' => localneta_sanitize_git_text('git ' . implode(' ', $args)),
        ];
    }

    return [
        'ok' => $exitCode === 0,
        'exit_code' => (int)$exitCode,
        'stdout' => localneta_sanitize_git_text(trim($stdout)),
        'stderr' => localneta_sanitize_git_text(trim($stderr)),
        'command' => localneta_sanitize_git_text('git ' . implode(' ', $args)),
    ];
}

function localneta_vcs_status(): array {
    $appMeta = localneta_app_version();
    $targetBranch = localneta_github_branch();
    $expectedRepo = localneta_github_repo_url();
    $repoRoot = localneta_repo_root();
    $gitBin = localneta_find_git_binary();

    $info = [
        'app' => $appMeta,
        'expected_repo' => $expectedRepo,
        'target_branch' => $targetBranch,
        'repo_root' => $repoRoot,
        'git_available' => $gitBin !== null && function_exists('proc_open'),
        'git_binary' => $gitBin,
        'git_version' => '',
        'is_repo' => is_dir($repoRoot . DIRECTORY_SEPARATOR . '.git') || is_file($repoRoot . DIRECTORY_SEPARATOR . '.git'),
        'branch' => $targetBranch,
        'head_short' => '',
        'head_full' => '',
        'head_subject' => '',
        'head_date' => '',
        'head_author' => '',
        'origin_url' => '',
        'tracking_ref' => 'origin/' . $targetBranch,
        'remote_ref_exists' => false,
        'remote_head_short' => '',
        'remote_head_full' => '',
        'ahead_count' => 0,
        'behind_count' => 0,
        'dirty_files' => [],
        'untracked_files' => [],
        'recent_commits' => [],
        'incoming_commits' => [],
        'status_state' => 'not_initialized',
        'status_label' => 'Git Not Initialized',
    ];

    if (!$info['git_available']) {
        $info['status_state'] = 'git_missing';
        $info['status_label'] = 'Git Unavailable';
        return $info;
    }

    $vRes = localneta_git_exec(['--version'], 5);
    if ($vRes['ok']) {
        $info['git_version'] = $vRes['stdout'];
    }

    if (!$info['is_repo']) {
        return $info;
    }

    $wtRes = localneta_git_exec(['rev-parse', '--is-inside-work-tree'], 5);
    if (!$wtRes['ok'] || trim($wtRes['stdout']) !== 'true') {
        $info['is_repo'] = false;
        return $info;
    }

    $brRes = localneta_git_exec(['rev-parse', '--abbrev-ref', 'HEAD'], 5);
    if ($brRes['ok'] && $brRes['stdout'] !== '' && $brRes['stdout'] !== 'HEAD') {
        $info['branch'] = $brRes['stdout'];
    }

    $remRes = localneta_git_exec(['remote', 'get-url', 'origin'], 5);
    if ($remRes['ok']) {
        $info['origin_url'] = $remRes['stdout'];
    }

    $headRes = localneta_git_exec(['log', '-1', '--format=%H%x1f%h%x1f%s%x1f%ci%x1f%an'], 5);
    if ($headRes['ok'] && $headRes['stdout'] !== '') {
        $parts = explode("\x1f", $headRes['stdout']);
        $info['head_full'] = $parts[0] ?? '';
        $info['head_short'] = $parts[1] ?? '';
        $info['head_subject'] = $parts[2] ?? '';
        $info['head_date'] = $parts[3] ?? '';
        $info['head_author'] = $parts[4] ?? '';
    }

    $stRes = localneta_git_exec(['status', '--porcelain'], 8);
    if ($stRes['ok'] && $stRes['stdout'] !== '') {
        foreach (preg_split('/\r?\n/', $stRes['stdout']) ?: [] as $line) {
            if (strlen($line) < 4) {
                continue;
            }
            $xy = substr($line, 0, 2);
            $file = trim(substr($line, 3));
            if ($xy === '??') {
                $info['untracked_files'][] = $file;
            } else {
                $info['dirty_files'][] = ['code' => trim($xy), 'path' => $file];
            }
        }
    }

    $logRes = localneta_git_exec(['log', '-n', '10', '--format=%H%x1f%h%x1f%s%x1f%ci%x1f%an'], 8);
    if ($logRes['ok'] && $logRes['stdout'] !== '') {
        foreach (preg_split('/\r?\n/', $logRes['stdout']) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $p = explode("\x1f", $line);
            $info['recent_commits'][] = [
                'full' => $p[0] ?? '',
                'short' => $p[1] ?? '',
                'subject' => $p[2] ?? '',
                'date' => $p[3] ?? '',
                'author' => $p[4] ?? '',
            ];
        }
    }

    $activeBranch = $info['branch'];
    $trackingRef = 'origin/' . $activeBranch;
    $info['tracking_ref'] = $trackingRef;

    $refRes = localneta_git_exec(['rev-parse', '--verify', $trackingRef], 5);
    if ($refRes['ok'] && $refRes['stdout'] !== '') {
        $info['remote_ref_exists'] = true;
        $info['remote_head_full'] = trim($refRes['stdout']);
        $info['remote_head_short'] = substr($info['remote_head_full'], 0, 7);

        if ($info['head_full'] !== '') {
            $lrRes = localneta_git_exec(['rev-list', '--left-right', '--count', 'HEAD...' . $trackingRef], 5);
            if ($lrRes['ok'] && preg_match('/^(\d+)\s+(\d+)$/', trim($lrRes['stdout']), $m)) {
                $info['ahead_count'] = (int)$m[1];
                $info['behind_count'] = (int)$m[2];
            }

            if ($info['behind_count'] > 0) {
                $incRes = localneta_git_exec(['log', '--format=%H%x1f%h%x1f%s%x1f%ci%x1f%an', 'HEAD..' . $trackingRef], 8);
                if ($incRes['ok'] && $incRes['stdout'] !== '') {
                    foreach (preg_split('/\r?\n/', $incRes['stdout']) ?: [] as $line) {
                        if (trim($line) === '') {
                            continue;
                        }
                        $p = explode("\x1f", $line);
                        $info['incoming_commits'][] = [
                            'full' => $p[0] ?? '',
                            'short' => $p[1] ?? '',
                            'subject' => $p[2] ?? '',
                            'date' => $p[3] ?? '',
                            'author' => $p[4] ?? '',
                        ];
                    }
                }
            }
        }
    }

    if ($info['head_full'] === '') {
        $info['status_state'] = 'no_commits';
        $info['status_label'] = 'No Local Commits Yet';
    } elseif (!$info['remote_ref_exists']) {
        $info['status_state'] = 'no_remote_ref';
        $info['status_label'] = 'Remote Not Pushed / Not Fetched';
    } elseif ($info['ahead_count'] > 0 && $info['behind_count'] > 0) {
        $info['status_state'] = 'diverged';
        $info['status_label'] = 'Diverged (' . $info['ahead_count'] . ' ahead, ' . $info['behind_count'] . ' behind)';
    } elseif ($info['behind_count'] > 0) {
        $info['status_state'] = 'update_available';
        $info['status_label'] = $info['behind_count'] . ' Update' . ($info['behind_count'] === 1 ? '' : 's') . ' Available';
    } elseif ($info['ahead_count'] > 0) {
        $info['status_state'] = 'ahead';
        $info['status_label'] = $info['ahead_count'] . ' Local Commit' . ($info['ahead_count'] === 1 ? '' : 's') . ' Ahead';
    } else {
        $info['status_state'] = 'up_to_date';
        $info['status_label'] = 'Up to Date with GitHub';
    }

    return $info;
}

function localneta_vcs_fetch(): array {
    $status = localneta_vcs_status();
    if (!$status['git_available']) {
        return [
            'ok' => false,
            'message' => 'Git is not installed or proc_open() is unavailable on this server.',
            'output' => '',
            'status' => $status,
        ];
    }
    if (!$status['is_repo']) {
        return [
            'ok' => false,
            'message' => 'This directory is not initialized as a Git repository yet.',
            'output' => '',
            'status' => $status,
        ];
    }

    $res = localneta_git_exec(['fetch', 'origin', '--prune', '--tags'], 30);
    $freshStatus = localneta_vcs_status();
    $combinedOut = trim($res['stdout'] . "\n" . $res['stderr']);

    if (!$res['ok']) {
        return [
            'ok' => false,
            'message' => 'GitHub fetch failed: ' . ($res['stderr'] ?: 'Unknown error'),
            'output' => $combinedOut,
            'status' => $freshStatus,
        ];
    }

    if (!$freshStatus['remote_ref_exists']) {
        $msg = 'Fetched origin (' . ($freshStatus['origin_url'] ?: 'GitHub') . '), but remote branch ' . $freshStatus['tracking_ref'] . ' has no commits yet.';
    } elseif ($freshStatus['behind_count'] > 0) {
        $msg = 'Found ' . $freshStatus['behind_count'] . ' new commit(s) on GitHub ready to pull.';
    } else {
        $msg = 'Checked GitHub (' . $freshStatus['tracking_ref'] . '). Local codebase is up to date at ' . ($freshStatus['head_short'] ?: 'HEAD') . '.';
    }

    return [
        'ok' => true,
        'message' => $msg,
        'output' => $combinedOut !== '' ? $combinedOut : 'Fetch completed cleanly.',
        'status' => $freshStatus,
    ];
}

function localneta_vcs_pull(): array {
    $status = localneta_vcs_status();
    if (!$status['git_available']) {
        return [
            'ok' => false,
            'message' => 'Git is not installed or proc_open() is unavailable on this server.',
            'output' => '',
            'before' => '',
            'after' => '',
            'status' => $status,
        ];
    }
    if (!$status['is_repo']) {
        return [
            'ok' => false,
            'message' => 'This directory is not initialized as a Git repository yet.',
            'output' => '',
            'before' => '',
            'after' => '',
            'status' => $status,
        ];
    }

    if (!empty($status['dirty_files'])) {
        $names = array_map(static fn(array $d): string => $d['path'], array_slice($status['dirty_files'], 0, 5));
        return [
            'ok' => false,
            'message' => 'Pull aborted safely: ' . count($status['dirty_files']) . ' tracked file(s) have uncommitted local modifications (' . implode(', ', $names) . ').',
            'output' => 'Blocked pull because working tree has modified tracked files.',
            'before' => $status['head_short'],
            'after' => $status['head_short'],
            'status' => $status,
        ];
    }

    $fetchRes = localneta_git_exec(['fetch', 'origin', '--prune', '--tags'], 30);
    if (!$fetchRes['ok']) {
        return [
            'ok' => false,
            'message' => 'Could not reach GitHub: ' . ($fetchRes['stderr'] ?: 'Fetch failed.'),
            'output' => trim($fetchRes['stdout'] . "\n" . $fetchRes['stderr']),
            'before' => $status['head_short'],
            'after' => $status['head_short'],
            'status' => localneta_vcs_status(),
        ];
    }

    $preStatus = localneta_vcs_status();
    $branch = $preStatus['branch'];

    if (!$preStatus['remote_ref_exists']) {
        return [
            'ok' => false,
            'message' => 'Remote branch origin/' . $branch . ' does not exist on GitHub yet. Push your initial commit first.',
            'output' => trim($fetchRes['stdout'] . "\n" . $fetchRes['stderr']),
            'before' => $preStatus['head_short'],
            'after' => $preStatus['head_short'],
            'status' => $preStatus,
        ];
    }

    if ($preStatus['ahead_count'] > 0 && $preStatus['behind_count'] > 0) {
        return [
            'ok' => false,
            'message' => 'Cannot fast-forward: local branch has diverged (' . $preStatus['ahead_count'] . ' ahead, ' . $preStatus['behind_count'] . ' behind). Resolve via server Git CLI.',
            'output' => 'Fast-forward pull declined to prevent merge conflicts.',
            'before' => $preStatus['head_short'],
            'after' => $preStatus['head_short'],
            'status' => $preStatus,
        ];
    }

    if ($preStatus['behind_count'] === 0) {
        $msg = $preStatus['ahead_count'] > 0
            ? 'Local branch is ' . $preStatus['ahead_count'] . ' commit(s) ahead of GitHub. Nothing to pull.'
            : 'Already up to date with GitHub (' . ($preStatus['head_short'] ?: 'HEAD') . ').';
        return [
            'ok' => true,
            'message' => $msg,
            'output' => trim($fetchRes['stdout'] . "\n" . $fetchRes['stderr']) ?: 'Already up to date.',
            'before' => $preStatus['head_short'],
            'after' => $preStatus['head_short'],
            'status' => $preStatus,
        ];
    }

    $beforeSha = $preStatus['head_short'];
    $pullRes = localneta_git_exec(['pull', '--ff-only', 'origin', $branch], 45);
    $combinedOut = trim($pullRes['stdout'] . "\n" . $pullRes['stderr']);

    if (!$pullRes['ok']) {
        return [
            'ok' => false,
            'message' => 'GitHub pull failed: ' . ($pullRes['stderr'] ?: 'git pull returned exit code ' . $pullRes['exit_code']),
            'output' => $combinedOut,
            'before' => $beforeSha,
            'after' => $beforeSha,
            'status' => localneta_vcs_status(),
        ];
    }

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    if (isset($_SESSION['ppms_village_summary_cache'])) {
        unset($_SESSION['ppms_village_summary_cache']);
    }

    $postStatus = localneta_vcs_status();
    $afterSha = $postStatus['head_short'];

    return [
        'ok' => true,
        'message' => 'Updated LocalNeta from GitHub (' . ($beforeSha ?: 'init') . ' -> ' . $afterSha . ', v' . ($postStatus['app']['version'] ?? '1.2.0') . ').',
        'output' => $combinedOut !== '' ? $combinedOut : 'Fast-forward pull completed.',
        'before' => $beforeSha,
        'after' => $afterSha,
        'status' => $postStatus,
    ];
}

function localneta_vcs_path_stats(string $relPath): array {
    $abs = localneta_repo_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relPath, '/'));
    if (is_file($abs)) {
        return ['exists' => true, 'type' => 'file', 'count' => 1, 'bytes' => (int)@filesize($abs)];
    }
    if (is_dir($abs)) {
        $count = 0;
        $bytes = 0;
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $fileInfo) {
                if ($fileInfo->isFile()) {
                    $count++;
                    $bytes += (int)$fileInfo->getSize();
                    if ($count >= 500) {
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            // Ignore unreadable paths
        }
        return ['exists' => true, 'type' => 'dir', 'count' => $count, 'bytes' => $bytes];
    }
    return ['exists' => false, 'type' => 'none', 'count' => 0, 'bytes' => 0];
}

function localneta_format_bytes(int $bytes): string {
    if ($bytes <= 0) {
        return '0 B';
    }
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 2) . ' MB';
}

function localneta_vcs_excluded_items(): array {
    $root = localneta_repo_root();
    $trackedList = [];
    if (is_dir($root . DIRECTORY_SEPARATOR . '.git')) {
        $lsRes = localneta_git_exec(['ls-files'], 8);
        if ($lsRes['ok'] && $lsRes['stdout'] !== '') {
            foreach (preg_split('/\r?\n/', $lsRes['stdout']) ?: [] as $tf) {
                $trackedList[strtolower(trim($tf))] = true;
            }
        }
    }

    // Batch check git-ignore rules against representative sample paths
    $probePaths = [
        'includes/config.local.php',
        'ppms/includes/config.local.php',
        '.env',
        'database_backup.sql',
        'includes/mlc_seed.json',
        'data/MP_RS_2026.xls',
        'assets/uploads/avatars/avatar-sample.jpg',
        'logs/app.log',
        'verify_app.py',
    ];
    $ignoredSet = [];
    if (is_dir($root . DIRECTORY_SEPARATOR . '.git')) {
        $chkRes = localneta_git_exec(array_merge(['check-ignore'], $probePaths), 5);
        if ($chkRes['stdout'] !== '') {
            foreach (preg_split('/\r?\n/', $chkRes['stdout']) ?: [] as $ig) {
                $ignoredSet[strtolower(trim($ig))] = true;
            }
        }
    }

    // Find any local .sql / backup files in root, backups/, or dumps/ to report presence accurately
    $sqlCount = 0;
    $sqlBytes = 0;
    try {
        $topItems = @scandir($root) ?: [];
        foreach ($topItems as $item) {
            if ($item === '.' || $item === '..' || $item === '.git') {
                continue;
            }
            $full = $root . DIRECTORY_SEPARATOR . $item;
            if (is_file($full) && preg_match('/\.(?:sql|sql\.gz|sql\.bz2|sql\.xz|sql\.zip|sqlite|sqlite3|db|dump|bak)$/i', $item)) {
                $sqlCount++;
                $sqlBytes += (int)@filesize($full);
            }
        }
        foreach (['backups', 'dumps'] as $bDir) {
            $bStats = localneta_vcs_path_stats($bDir);
            if ($bStats['exists']) {
                $sqlCount += (int)$bStats['count'];
                $sqlBytes += (int)$bStats['bytes'];
            }
        }
    } catch (Throwable $e) {
    }

    $specs = [
        [
            'category' => 'Secrets & Config',
            'pattern' => '/includes/config.local.php',
            'probe' => 'includes/config.local.php',
            'disk_path' => 'includes/config.local.php',
            'reason' => 'Live MySQL host, database name, username, password & optional SMS gateway keys.',
        ],
        [
            'category' => 'Secrets & Config',
            'pattern' => '/ppms/includes/config.local.php, *.local.php, .env, .env.*',
            'probe' => '.env',
            'disk_path' => '.env',
            'reason' => 'Environment override files & private API tokens.',
        ],
        [
            'category' => 'SQL & DB Dumps',
            'pattern' => '*.sql, *.sql.gz, *.sql.zip, *.sqlite*, *.db, *.dump, *.bak, /backups/, /dumps/',
            'probe' => 'database_backup.sql',
            'disk_count' => $sqlCount,
            'disk_bytes' => $sqlBytes,
            'reason' => 'MySQL exports, backups & citizen/politician database tables.',
        ],
        [
            'category' => 'Sensitive PII Data',
            'pattern' => '/includes/mlc_seed.json',
            'probe' => 'includes/mlc_seed.json',
            'disk_path' => 'includes/mlc_seed.json',
            'reason' => 'Contains raw Legislative Council contact phone numbers and personal emails.',
        ],
        [
            'category' => 'Raw Source Archives',
            'pattern' => '/data/, *.xls, *.xlsx, *.pdf, *.csv (except /includes/mp_ls18.csv)',
            'probe' => 'data/MP_RS_2026.xls',
            'disk_path' => 'data',
            'reason' => 'Large ECI PDF notifications & official XLS/XLSX datasets (~12 MB).',
        ],
        [
            'category' => 'User Uploads',
            'pattern' => '/assets/uploads/',
            'probe' => 'assets/uploads/avatars/avatar-sample.jpg',
            'disk_path' => 'assets/uploads',
            'reason' => 'User-uploaded citizen & leader profile avatars.',
        ],
        [
            'category' => 'Logs, Cache & Tooling',
            'pattern' => '*.log, /logs/, /tmp/, /cache/, /vendor/, /node_modules/, /verify_*.py',
            'probe' => 'logs/app.log',
            'disk_path' => 'logs',
            'reason' => 'Server logs, temporary caches, dependencies, IDE & local test scripts.',
        ],
    ];

    $out = [];
    foreach ($specs as $sp) {
        $probeKey = strtolower($sp['probe']);
        $ignored = isset($ignoredSet[$probeKey]);
        $trackedConflict = isset($trackedList[$probeKey]);
        if ($sp['category'] === 'SQL & DB Dumps') {
            foreach (array_keys($trackedList) as $tf) {
                if (preg_match('/\.(?:sql|sql\.gz|sql\.zip|sqlite|sqlite3|db|dump|bak)$/i', $tf)) {
                    $trackedConflict = true;
                    break;
                }
            }
        }

        if (isset($sp['disk_count'])) {
            $stats = [
                'exists' => $sp['disk_count'] > 0,
                'count' => (int)$sp['disk_count'],
                'bytes' => (int)$sp['disk_bytes'],
            ];
        } else {
            $stats = localneta_vcs_path_stats((string)$sp['disk_path']);
        }

        $out[] = [
            'category' => $sp['category'],
            'pattern' => $sp['pattern'],
            'reason' => $sp['reason'],
            'exists_on_disk' => $stats['exists'],
            'file_count' => $stats['count'],
            'size_label' => $stats['exists'] ? localneta_format_bytes($stats['bytes']) : 'Not present',
            'is_ignored' => $ignored && !$trackedConflict,
            'is_tracked_warning' => $trackedConflict,
        ];
    }

    return $out;
}
