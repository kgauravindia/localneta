<?php
// includes/vcs.php - Dual-Engine Git & GitHub HTTPS Sync Helper for LocalNeta

if (!defined('APP_INIT') && !defined('PPMS_INIT')) {
    http_response_code(403);
    exit('Forbidden');
}

function localneta_repo_root(): string {
    $root = realpath(__DIR__ . '/..');
    return $root !== false ? $root : dirname(__DIR__);
}

function localneta_app_version(bool $reload = false): array {
    static $meta = null;
    if ($meta !== null && !$reload) {
        return $meta;
    }

    $defaults = [
        'name' => 'LocalNeta',
        'version' => '1.2.3',
        'codename' => 'Tiranga Civic & ECI Calendar',
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
    if (@is_file($versionFile)) {
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

function localneta_github_owner_repo(): ?array {
    $url = localneta_github_repo_url();
    if (preg_match('#^https://github\.com/([A-Za-z0-9._-]+)/([A-Za-z0-9._-]+?)(?:\.git)?$#', $url, $m)) {
        return ['owner' => $m[1], 'repo' => $m[2]];
    }
    return null;
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

function localneta_is_php_func_callable(string $func): bool {
    if (!function_exists($func)) {
        return false;
    }
    $disabled = (string)@ini_get('disable_functions');
    if ($disabled !== '') {
        $blocked = array_map('trim', explode(',', strtolower($disabled)));
        if (in_array(strtolower($func), $blocked, true)) {
            return false;
        }
    }
    return true;
}

function localneta_find_git_binary(): ?string {
    static $resolved = false;
    static $cached = null;
    if ($resolved) {
        return $cached;
    }
    $resolved = true;

    $envBin = trim((string)getenv('LOCALNETA_GIT_BIN'));
    if ($envBin !== '' && @is_file($envBin)) {
        $cached = $envBin;
        return $cached;
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        $candidates = [
            'C:\\Program Files\\Git\\cmd\\git.exe',
            'C:\\Program Files\\Git\\bin\\git.exe',
            'C:\\Program Files (x86)\\Git\\cmd\\git.exe',
            'D:\\Program Files\\Git\\cmd\\git.exe',
            'C:\\Laragon\\bin\\git\\cmd\\git.exe',
            'D:\\laragon\\bin\\git\\cmd\\git.exe',
        ];
        foreach ($candidates as $path) {
            if (@is_file($path)) {
                $cached = $path;
                return $cached;
            }
        }
        if (localneta_is_php_func_callable('shell_exec')) {
            $whereOut = @shell_exec('where git 2>NUL');
            if (is_string($whereOut)) {
                foreach (preg_split('/\r?\n/', trim($whereOut)) ?: [] as $line) {
                    $line = trim($line);
                    if ($line !== '' && @is_file($line)) {
                        $cached = $line;
                        return $cached;
                    }
                }
            }
        }
    } else {
        $candidates = ['/usr/bin/git', '/usr/local/bin/git', '/bin/git'];
        foreach ($candidates as $path) {
            if (@is_file($path) && @is_executable($path)) {
                $cached = $path;
                return $cached;
            }
        }
        // Fallback when PHP open_basedir hides /usr/bin/git from is_file()
        if (localneta_is_php_func_callable('exec')) {
            $out = [];
            $code = 1;
            @exec('git --version 2>/dev/null', $out, $code);
            if ($code === 0 && !empty($out)) {
                $cached = 'git';
                return $cached;
            }
        } elseif (localneta_is_php_func_callable('shell_exec')) {
            $whichOut = @shell_exec('command -v git 2>/dev/null');
            $whichLine = is_string($whichOut) ? trim($whichOut) : '';
            if ($whichLine !== '') {
                $cached = $whichLine;
                return $cached;
            }
        }
    }

    return null;
}

function localneta_vcs_build_env(): array {
    $env = [];
    $procEnv = @getenv();
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
            'stderr' => 'Git CLI binary not found on server.',
            'command' => 'git ' . implode(' ', $args),
        ];
    }

    $repoRoot = localneta_repo_root();
    $cmdParts = array_merge([$gitBin, '-c', 'safe.directory=*', '-C', $repoRoot], $args);

    if (localneta_is_php_func_callable('proc_open')) {
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = @proc_open($cmdParts, $desc, $pipes, $repoRoot, localneta_vcs_build_env());
        if (is_resource($proc)) {
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
                'stdout' => localneta_sanitize_git_text(rtrim($stdout, "\r\n")),
                'stderr' => localneta_sanitize_git_text(trim($stderr)),
                'command' => localneta_sanitize_git_text('git ' . implode(' ', $args)),
            ];
        }
    }

    if (localneta_is_php_func_callable('exec')) {
        $escaped = array_map('escapeshellarg', $cmdParts);
        $prefix = DIRECTORY_SEPARATOR === '\\'
            ? 'set GIT_TERMINAL_PROMPT=0 && set GCM_INTERACTIVE=never && '
            : 'GIT_TERMINAL_PROMPT=0 GCM_INTERACTIVE=never LC_ALL=C ';
        $cmdLine = $prefix . implode(' ', $escaped) . ' 2>&1';
        $outLines = [];
        $exitCode = 1;
        @exec($cmdLine, $outLines, $exitCode);
        $combined = localneta_sanitize_git_text(rtrim(implode("\n", $outLines), "\r\n"));
        return [
            'ok' => $exitCode === 0,
            'exit_code' => (int)$exitCode,
            'stdout' => $exitCode === 0 ? $combined : '',
            'stderr' => $exitCode === 0 ? '' : trim($combined),
            'command' => localneta_sanitize_git_text('git ' . implode(' ', $args)),
        ];
    }

    return [
        'ok' => false,
        'exit_code' => -1,
        'stdout' => '',
        'stderr' => 'Shell execution functions (proc_open/exec) are disabled by server PHP configuration.',
        'command' => 'git ' . implode(' ', $args),
    ];
}

function localneta_vcs_capabilities(): array {
    $gitBin = localneta_find_git_binary();
    $procOk = localneta_is_php_func_callable('proc_open');
    $execOk = localneta_is_php_func_callable('exec');
    $shellOk = $procOk || $execOk;
    $repoRoot = localneta_repo_root();
    $hasDotGit = @is_dir($repoRoot . DIRECTORY_SEPARATOR . '.git') || @is_file($repoRoot . DIRECTORY_SEPARATOR . '.git');
    $curlOk = localneta_is_php_func_callable('curl_init') && localneta_is_php_func_callable('curl_exec');
    $fopenUrlOk = (bool)@ini_get('allow_url_fopen') && localneta_is_php_func_callable('file_get_contents');
    $httpOk = $curlOk || $fopenUrlOk;
    $zipOk = class_exists('ZipArchive');

    $cliReady = ($gitBin !== null) && $shellOk && $hasDotGit;
    $httpsReady = $httpOk;

    $mode = 'unavailable';
    $reason = '';
    if ($cliReady) {
        $mode = 'git_cli';
        $reason = 'Running native Git CLI engine.';
    } elseif ($httpsReady) {
        $mode = 'github_https';
        if (!$shellOk) {
            $reason = 'Shared-hosting PHP disabled proc_open/exec; automatically switched to GitHub HTTPS Sync.';
        } elseif ($gitBin === null) {
            $reason = 'Git binary is not installed in web PHP environment; automatically switched to GitHub HTTPS Sync.';
        } else {
            $reason = 'Deployed without .git directory; automatically switched to GitHub HTTPS Sync.';
        }
    } else {
        $reason = 'Neither Git CLI (proc_open/exec) nor PHP cURL / allow_url_fopen is enabled on this server.';
    }

    return [
        'mode' => $mode,
        'reason' => $reason,
        'git_bin' => $gitBin,
        'proc_open' => $procOk,
        'exec' => $execOk,
        'shell_exec_ready' => $shellOk,
        'has_dot_git' => $hasDotGit,
        'curl' => $curlOk,
        'allow_url_fopen' => $fopenUrlOk,
        'http_ready' => $httpOk,
        'zip_ready' => $zipOk,
        'cli_ready' => $cliReady,
        'https_ready' => $httpsReady,
    ];
}

function localneta_vcs_state_path(): string {
    return localneta_repo_root() . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'vcs_state.local.php';
}

function localneta_vcs_load_state(): array {
    $path = localneta_vcs_state_path();
    if (!@is_file($path)) {
        return [];
    }
    $data = @include $path;
    return is_array($data) ? $data : [];
}

function localneta_vcs_save_state(array $state): bool {
    $path = localneta_vcs_state_path();
    $code = "<?php\n"
        . "// Local GitHub HTTPS sync state (gitignored via *.local.php)\n"
        . "if (!defined('APP_INIT') && !defined('PPMS_INIT')) {\n"
        . "    http_response_code(403);\n"
        . "    exit('Forbidden');\n"
        . "}\n"
        . 'return ' . var_export($state, true) . ";\n";
    return @file_put_contents($path, $code, LOCK_EX) !== false;
}

function localneta_http_get(string $url, ?string $destFile = null, int $timeoutSec = 30): array {
    $isGitHubApi = str_starts_with(strtolower($url), 'https://api.github.com/');
    $headers = $isGitHubApi
        ? ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28']
        : ['Accept: */*'];

    if (localneta_is_php_func_callable('curl_init') && localneta_is_php_func_callable('curl_exec')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Failed to initialize cURL.'];
        }
        $fp = null;
        if ($destFile !== null) {
            $fp = @fopen($destFile, 'wb');
            if ($fp === false) {
                curl_close($ch);
                return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Cannot open temp file for download: ' . $destFile];
            }
            curl_setopt($ch, CURLOPT_FILE, $fp);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }

        curl_setopt_array($ch, [
            CURLOPT_MAXREDIRS => 6,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'LocalNeta-PPMS-Updater/1.2',
            CURLOPT_HTTPHEADER => $headers,
        ]);
        @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($fp !== null) {
            @fclose($fp);
        }

        if ($err !== '') {
            return ['ok' => false, 'status' => $code, 'body' => '', 'error' => 'cURL error: ' . $err];
        }
        if ($code < 200 || $code >= 300) {
            $snippet = is_string($raw) ? substr(trim($raw), 0, 220) : '';
            return ['ok' => false, 'status' => $code, 'body' => is_string($raw) ? $raw : '', 'error' => 'HTTP ' . $code . ($snippet !== '' ? ' (' . $snippet . ')' : '')];
        }
        return ['ok' => true, 'status' => $code, 'body' => is_string($raw) ? $raw : '', 'error' => ''];
    }

    if ((bool)@ini_get('allow_url_fopen') && localneta_is_php_func_callable('file_get_contents')) {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSec,
                'follow_location' => 1,
                'header' => "User-Agent: LocalNeta-PPMS-Updater/1.2\r\n" . implode("\r\n", $headers) . "\r\n",
            ],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'HTTPS request failed via allow_url_fopen.'];
        }
        if ($destFile !== null) {
            if (@file_put_contents($destFile, $data, LOCK_EX) === false) {
                return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Failed writing downloaded archive to disk.'];
            }
            return ['ok' => true, 'status' => 200, 'body' => '', 'error' => ''];
        }
        return ['ok' => true, 'status' => 200, 'body' => $data, 'error' => ''];
    }

    return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Neither cURL extension nor allow_url_fopen is enabled in PHP.'];
}

function localneta_vcs_is_protected_relpath(string $relPath): bool {
    $p = ltrim(str_replace('\\', '/', $relPath), '/');
    if ($p === '' || str_contains($p, '..') || str_contains($p, "\0")) {
        return true;
    }
    $low = strtolower($p);

    $protectedPrefixes = [
        '.git/',
        'data/',
        'assets/uploads/',
        'backups/',
        'dumps/',
        'logs/',
        'tmp/',
        'temp/',
        'cache/',
        'vendor/',
        'node_modules/',
        '.vscode/',
        '.idea/',
    ];
    foreach ($protectedPrefixes as $pref) {
        if (str_starts_with($low, $pref)) {
            return true;
        }
    }

    $exactProtected = [
        'includes/config.local.php',
        'ppms/includes/config.local.php',
        'includes/vcs_state.local.php',
        'includes/mlc_seed.json',
        '.env',
    ];
    if (in_array($low, $exactProtected, true)) {
        return true;
    }

    if (preg_match('/\.local\.php$/i', $p) || preg_match('/(?:^|\/)\.env(?:\.|$)/i', $p)) {
        return true;
    }
    if (preg_match('/\.(?:sql|sql\.gz|sql\.bz2|sql\.xz|sql\.zip|sqlite|sqlite3|db|dump|bak|log|tmp|swp|swo|xls|xlsx|pdf)$/i', $p)) {
        return true;
    }
    if (preg_match('/^verify_.*\.py$/i', $p)) {
        return true;
    }
    return false;
}

function localneta_parse_git_raw_log(string $rawText): array {
    $commits = [];
    $current = null;
    $inBody = false;

    foreach (preg_split('/\r?\n/', $rawText) ?: [] as $line) {
        if (preg_match('/^commit\s+([0-9a-f]{7,64})$/i', $line, $cm)) {
            if ($current !== null) {
                $commits[] = $current;
            }
            $sha = $cm[1];
            $current = [
                'full' => $sha,
                'short' => substr($sha, 0, 7),
                'subject' => '',
                'date' => '',
                'author' => '',
            ];
            $inBody = false;
            continue;
        }
        if ($current === null) {
            continue;
        }
        if (!$inBody) {
            if ($line === '') {
                $inBody = true;
            } elseif (preg_match('/^author\s+(.*?)\s+<[^>]*>\s+(\d+)\s+[+-]\d{4}/', $line, $am)) {
                $current['author'] = trim($am[1]);
                $current['date'] = date('Y-m-d H:i:s', (int)$am[2]);
            }
        } elseif ($current['subject'] === '' && preg_match('/^    (.*)$/', $line, $sm)) {
            $sub = trim($sm[1]);
            if ($sub !== '') {
                $current['subject'] = $sub;
            }
        }
    }
    if ($current !== null) {
        $commits[] = $current;
    }
    return $commits;
}

function localneta_vcs_status(): array {
    $appMeta = localneta_app_version();
    $targetBranch = localneta_github_branch();
    $expectedRepo = localneta_github_repo_url();
    $repoRoot = localneta_repo_root();
    $caps = localneta_vcs_capabilities();

    $info = [
        'app' => $appMeta,
        'expected_repo' => $expectedRepo,
        'target_branch' => $targetBranch,
        'repo_root' => $repoRoot,
        'caps' => $caps,
        'sync_mode' => $caps['mode'], // 'git_cli' | 'github_https' | 'unavailable'
        'git_available' => $caps['cli_ready'],
        'https_available' => $caps['https_ready'],
        'git_binary' => $caps['git_bin'],
        'git_version' => '',
        'is_repo' => $caps['has_dot_git'],
        'branch' => $targetBranch,
        'head_short' => '',
        'head_full' => '',
        'head_subject' => '',
        'head_date' => '',
        'head_author' => '',
        'origin_url' => $expectedRepo,
        'tracking_ref' => 'origin/' . $targetBranch,
        'remote_ref_exists' => false,
        'remote_head_short' => '',
        'remote_head_full' => '',
        'remote_version' => '',
        'ahead_count' => 0,
        'behind_count' => 0,
        'dirty_files' => [],
        'untracked_files' => [],
        'recent_commits' => [],
        'incoming_commits' => [],
        'last_fetched_at' => '',
        'status_state' => 'unavailable',
        'status_label' => 'Unavailable',
    ];

    if ($caps['mode'] === 'git_cli') {
        $vRes = localneta_git_exec(['--version'], 5);
        if ($vRes['ok']) {
            $info['git_version'] = $vRes['stdout'];
        }

        $wtRes = localneta_git_exec(['rev-parse', '--is-inside-work-tree'], 5);
        if ($wtRes['ok'] && trim($wtRes['stdout']) === 'true') {
            $brRes = localneta_git_exec(['rev-parse', '--abbrev-ref', 'HEAD'], 5);
            if ($brRes['ok'] && trim($brRes['stdout']) !== '' && trim($brRes['stdout']) !== 'HEAD') {
                $info['branch'] = trim($brRes['stdout']);
            }

            $remRes = localneta_git_exec(['remote', 'get-url', 'origin'], 5);
            if ($remRes['ok'] && trim($remRes['stdout']) !== '') {
                $info['origin_url'] = trim($remRes['stdout']);
            }

            $logRes = localneta_git_exec(['log', '-n', '10', '--pretty=raw'], 8);
            if ($logRes['ok'] && $logRes['stdout'] !== '') {
                $info['recent_commits'] = localneta_parse_git_raw_log($logRes['stdout']);
                if (!empty($info['recent_commits'][0])) {
                    $h0 = $info['recent_commits'][0];
                    $info['head_full'] = $h0['full'];
                    $info['head_short'] = $h0['short'];
                    $info['head_subject'] = $h0['subject'];
                    $info['head_date'] = $h0['date'];
                    $info['head_author'] = $h0['author'];
                }
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

            $activeBranch = $info['branch'];
            $trackingRef = 'origin/' . $activeBranch;
            $info['tracking_ref'] = $trackingRef;

            $refRes = localneta_git_exec(['rev-parse', '--verify', $trackingRef], 5);
            if ($refRes['ok'] && trim($refRes['stdout']) !== '') {
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
                        $incRes = localneta_git_exec(['log', '--pretty=raw', 'HEAD..' . $trackingRef], 8);
                        if ($incRes['ok'] && $incRes['stdout'] !== '') {
                            $info['incoming_commits'] = localneta_parse_git_raw_log($incRes['stdout']);
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

        // Fall through to HTTPS mode if .git was corrupt
        $info['sync_mode'] = $caps['https_ready'] ? 'github_https' : 'unavailable';
    }

    if ($info['sync_mode'] === 'github_https') {
        $state = localneta_vcs_load_state();
        $info['head_full'] = (string)($state['local_head_full'] ?? '');
        $info['head_short'] = (string)($state['local_head_short'] ?? ($info['head_full'] !== '' ? substr($info['head_full'], 0, 7) : ''));
        $info['head_subject'] = (string)($state['local_head_subject'] ?? '');
        $info['head_date'] = (string)($state['local_head_date'] ?? '');
        $info['head_author'] = (string)($state['local_head_author'] ?? '');

        $info['remote_head_full'] = (string)($state['remote_head_full'] ?? '');
        $info['remote_head_short'] = (string)($state['remote_head_short'] ?? ($info['remote_head_full'] !== '' ? substr($info['remote_head_full'], 0, 7) : ''));
        $info['remote_version'] = (string)($state['remote_version'] ?? '');
        $info['last_fetched_at'] = (string)($state['last_fetched_at'] ?? '');

        $remoteCommits = is_array($state['remote_commits'] ?? null) ? $state['remote_commits'] : [];
        $info['recent_commits'] = $remoteCommits;
        $info['remote_ref_exists'] = $info['remote_head_full'] !== '';

        if ($info['remote_head_full'] !== '') {
            if ($info['head_full'] !== '' && hash_equals($info['head_full'], $info['remote_head_full'])) {
                $info['behind_count'] = 0;
                $info['status_state'] = 'up_to_date';
                $info['status_label'] = 'Up to Date (GitHub HTTPS)';
            } else {
                $incoming = [];
                $foundLocal = false;
                foreach ($remoteCommits as $rc) {
                    if ($info['head_full'] !== '' && ($rc['full'] ?? '') === $info['head_full']) {
                        $foundLocal = true;
                        break;
                    }
                    $incoming[] = $rc;
                }
                if ($info['head_full'] === '') {
                    $info['incoming_commits'] = $remoteCommits;
                    $info['behind_count'] = max(1, count($remoteCommits));
                    $info['status_state'] = 'update_available';
                    $info['status_label'] = 'Ready to Link & Sync (HTTPS)';
                } else {
                    $info['incoming_commits'] = $incoming;
                    $info['behind_count'] = $foundLocal ? count($incoming) : max(1, count($incoming));
                    $info['status_state'] = 'update_available';
                    $info['status_label'] = $info['behind_count'] . ' Update' . ($info['behind_count'] === 1 ? '' : 's') . ' Available (HTTPS)';
                }
            }
        } elseif ($info['remote_version'] !== '' && version_compare($info['remote_version'], (string)($appMeta['version'] ?? '0'), '>')) {
            $info['behind_count'] = 1;
            $info['status_state'] = 'update_available';
            $info['status_label'] = 'v' . $info['remote_version'] . ' Available (HTTPS)';
        } elseif ($info['remote_version'] !== '' && $info['last_fetched_at'] !== '') {
            $info['status_state'] = 'https_ready';
            $info['status_label'] = 'v' . ($appMeta['version'] ?? '1.2.1') . ' Matches Remote (Click Sync to Link SHA)';
        } else {
            $info['status_state'] = 'https_ready';
            $info['status_label'] = 'HTTPS Sync Ready (Click Check Updates)';
        }

        return $info;
    }

    $info['status_state'] = 'git_missing';
    $info['status_label'] = 'Git & HTTPS Unavailable';
    return $info;
}

function localneta_vcs_fetch_https(): array {
    $ownerRepo = localneta_github_owner_repo();
    $branch = localneta_github_branch();
    if (!$ownerRepo) {
        return [
            'ok' => false,
            'message' => 'Invalid GitHub repository URL configured.',
            'output' => '',
            'status' => localneta_vcs_status(),
        ];
    }

    $state = localneta_vcs_load_state();
    $owner = $ownerRepo['owner'];
    $repo = $ownerRepo['repo'];
    $apiUrl = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo)
        . '/commits?sha=' . rawurlencode($branch) . '&per_page=10';

    $outLog = ['GET ' . $apiUrl];
    $res = localneta_http_get($apiUrl, null, 20);
    $commits = [];
    if ($res['ok']) {
        $json = json_decode($res['body'], true);
        if (is_array($json)) {
            foreach ($json as $row) {
                $sha = (string)($row['sha'] ?? '');
                if ($sha === '') {
                    continue;
                }
                $msgLines = preg_split('/\r?\n/', trim((string)($row['commit']['message'] ?? '')));
                $commits[] = [
                    'full' => $sha,
                    'short' => substr($sha, 0, 7),
                    'subject' => (string)($msgLines[0] ?? 'Commit'),
                    'date' => (string)($row['commit']['author']['date'] ?? ''),
                    'author' => (string)($row['commit']['author']['name'] ?? 'GitHub'),
                ];
            }
        }
        $outLog[] = 'GitHub API HTTP ' . $res['status'] . ' -> ' . count($commits) . ' commit(s) fetched.';
    } else {
        $outLog[] = 'GitHub API notice: ' . $res['error'];
        // Fallback to public GitHub Atom commit feed (avoids 60/hr shared-IP REST API rate limit)
        $atomUrl = 'https://github.com/' . rawurlencode($owner) . '/' . rawurlencode($repo)
            . '/commits/' . rawurlencode($branch) . '.atom';
        $atomRes = localneta_http_get($atomUrl, null, 18);
        if ($atomRes['ok'] && function_exists('simplexml_load_string')) {
            $prevUseErrors = @libxml_use_internal_errors(true);
            $xml = @simplexml_load_string($atomRes['body']);
            if ($xml !== false && isset($xml->entry)) {
                foreach ($xml->entry as $entry) {
                    $idStr = trim((string)($entry->id ?? ''));
                    if (preg_match('/Grit::Commit\/([a-f0-9]{40})/i', $idStr, $m)) {
                        $sha = $m[1];
                        $commits[] = [
                            'full' => $sha,
                            'short' => substr($sha, 0, 7),
                            'subject' => trim((string)($entry->title ?? 'Commit')),
                            'date' => trim((string)($entry->updated ?? '')),
                            'author' => trim((string)($entry->author->name ?? 'GitHub')),
                        ];
                        if (count($commits) >= 10) {
                            break;
                        }
                    }
                }
            }
            @libxml_clear_errors();
            @libxml_use_internal_errors($prevUseErrors);
            if (!empty($commits)) {
                $outLog[] = 'GitHub Atom Feed HTTP ' . $atomRes['status'] . ' -> ' . count($commits) . ' commit(s) fetched.';
            }
        }
    }

    // Also fetch lightweight raw version.json (no API rate limit)
    $rawVerUrl = 'https://raw.githubusercontent.com/' . rawurlencode($owner) . '/' . rawurlencode($repo)
        . '/' . rawurlencode($branch) . '/version.json';
    $verRes = localneta_http_get($rawVerUrl, null, 15);
    $remoteVersion = '';
    if ($verRes['ok']) {
        $vData = json_decode($verRes['body'], true);
        if (is_array($vData) && !empty($vData['version'])) {
            $remoteVersion = (string)$vData['version'];
            $outLog[] = 'Remote version.json -> v' . $remoteVersion . ' (' . ($vData['released_at'] ?? '') . ')';
        }
    } else {
        $outLog[] = 'Raw version.json notice: ' . $verRes['error'];
    }

    if (empty($commits) && $remoteVersion === '') {
        return [
            'ok' => false,
            'message' => 'Could not reach GitHub API/Raw over HTTPS: ' . ($res['error'] ?: $verRes['error']),
            'output' => implode("\n", $outLog),
            'status' => localneta_vcs_status(),
        ];
    }

    if (!empty($commits)) {
        $state['remote_head_full'] = $commits[0]['full'];
        $state['remote_head_short'] = $commits[0]['short'];
        $state['remote_commits'] = $commits;
    }
    if ($remoteVersion !== '') {
        $state['remote_version'] = $remoteVersion;
    }
    $state['last_fetched_at'] = date('Y-m-d H:i:s');
    localneta_vcs_save_state($state);

    $freshStatus = localneta_vcs_status();
    if ($freshStatus['status_state'] === 'up_to_date') {
        $msg = 'Checked GitHub (' . $branch . '). Live server is up to date at ' . ($freshStatus['head_short'] ?: 'v' . $remoteVersion) . '.';
    } elseif ($freshStatus['behind_count'] > 0 && $freshStatus['remote_head_short'] !== '') {
        $msg = 'GitHub check complete: ' . ($remoteVersion !== '' ? ('v' . $remoteVersion . ' / ') : '') . $freshStatus['remote_head_short'] . ' is available to sync.';
    } else {
        $msg = 'Checked GitHub (' . $branch . ') via HTTPS API.';
    }

    return [
        'ok' => true,
        'message' => $msg,
        'output' => implode("\n", $outLog),
        'status' => $freshStatus,
    ];
}

function localneta_vcs_pull_https(): array {
    if (!class_exists('ZipArchive')) {
        return [
            'ok' => false,
            'message' => 'PHP ZipArchive extension (ext-zip) is not enabled on this server. Enable ext-zip in cPanel/Hostinger PHP Extensions to pull via HTTPS.',
            'output' => 'Missing PHP ZipArchive class.',
            'before' => '',
            'after' => '',
            'status' => localneta_vcs_status(),
        ];
    }

    $ownerRepo = localneta_github_owner_repo();
    $branch = localneta_github_branch();
    if (!$ownerRepo) {
        return [
            'ok' => false,
            'message' => 'Invalid GitHub repository URL.',
            'output' => '',
            'before' => '',
            'after' => '',
            'status' => localneta_vcs_status(),
        ];
    }

    $fetchRes = localneta_vcs_fetch_https();
    $preStatus = $fetchRes['status'] ?? localneta_vcs_status();
    $beforeSha = $preStatus['head_short'] ?: ('v' . ($preStatus['app']['version'] ?? ''));

    $targetCommit = $preStatus['recent_commits'][0] ?? null;
    $targetSha = is_array($targetCommit) ? (string)($targetCommit['full'] ?? '') : '';
    $owner = $ownerRepo['owner'];
    $repo = $ownerRepo['repo'];
    $zipUrl = $targetSha !== ''
        ? ('https://codeload.github.com/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/zip/' . rawurlencode($targetSha))
        : ('https://codeload.github.com/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/zip/refs/heads/' . rawurlencode($branch));

    $repoRoot = localneta_repo_root();
    $tmpDir = @sys_get_temp_dir();
    $tmpFile = ($tmpDir && @is_writable($tmpDir)) ? @tempnam($tmpDir, 'ln_gh_') : false;
    $createdTmpDir = false;
    if ($tmpFile === false) {
        $fallbackTmp = $repoRoot . DIRECTORY_SEPARATOR . 'tmp';
        if (!@is_dir($fallbackTmp)) {
            @mkdir($fallbackTmp, 0755, true);
            $createdTmpDir = true;
        }
        $tmpFile = $fallbackTmp . DIRECTORY_SEPARATOR . 'gh_sync_' . bin2hex(random_bytes(6)) . '.zip';
    } else {
        $zipPath = $tmpFile . '.zip';
        @unlink($tmpFile);
        $tmpFile = $zipPath;
    }

    $log = ['Downloading archive: ' . $zipUrl];
    try {
        $dl = localneta_http_get($zipUrl, $tmpFile, 60);
        if (!$dl['ok'] || !@is_file($tmpFile) || @filesize($tmpFile) < 512) {
            return [
                'ok' => false,
                'message' => 'Failed to download GitHub archive: ' . ($dl['error'] ?: 'Empty response'),
                'output' => implode("\n", $log),
                'before' => $beforeSha,
                'after' => $beforeSha,
                'status' => $preStatus,
            ];
        }

        $zip = new ZipArchive();
        $openOk = $zip->open($tmpFile);
        if ($openOk !== true) {
            return [
                'ok' => false,
                'message' => 'Downloaded archive could not be opened (ZipArchive code ' . $openOk . ').',
                'output' => implode("\n", $log),
                'before' => $beforeSha,
                'after' => $beforeSha,
                'status' => $preStatus,
            ];
        }

        // Detect top-level root folder inside GitHub zip (e.g. localneta-main/ or localneta-a9874c4.../)
        $rootPrefix = '';
        $hasIndexPhp = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if ($rootPrefix === '' && str_contains($name, '/')) {
                $rootPrefix = substr($name, 0, strpos($name, '/') + 1);
            }
            if ($rootPrefix !== '' && $name === $rootPrefix . 'index.php') {
                $hasIndexPhp = true;
                break;
            }
        }
        if ($rootPrefix === '' || !$hasIndexPhp) {
            $zip->close();
            return [
                'ok' => false,
                'message' => 'Downloaded archive structure is invalid (index.php missing). Aborted without modifying files.',
                'output' => implode("\n", $log),
                'before' => $beforeSha,
                'after' => $beforeSha,
                'status' => $preStatus,
            ];
        }

        $updatedCount = 0;
        $unchangedCount = 0;
        $skippedProtected = 0;
        $failedWrites = [];
        $prefixLen = strlen($rootPrefix);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = (string)$zip->getNameIndex($i);
            if (!str_starts_with($entryName, $rootPrefix)) {
                continue;
            }
            $rel = substr($entryName, $prefixLen);
            if ($rel === '' || str_ends_with($rel, '/')) {
                continue;
            }
            if (localneta_vcs_is_protected_relpath($rel)) {
                $skippedProtected++;
                continue;
            }

            $bytes = $zip->getFromIndex($i);
            if ($bytes === false) {
                $failedWrites[] = $rel;
                continue;
            }

            $destAbs = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $destDir = dirname($destAbs);
            if (!@is_dir($destDir) && !@mkdir($destDir, 0755, true)) {
                $failedWrites[] = $rel;
                continue;
            }

            // Skip identical unchanged files to minimize disk I/O
            if (@is_file($destAbs) && @filesize($destAbs) === strlen($bytes)) {
                $newHash = sha1($bytes);
                $oldHash = @sha1_file($destAbs);
                if ($oldHash !== false && hash_equals($oldHash, $newHash)) {
                    $unchangedCount++;
                    continue;
                }
            }

            if (@file_put_contents($destAbs, $bytes, LOCK_EX) === false) {
                $failedWrites[] = $rel;
            } else {
                $updatedCount++;
            }
        }
        $zip->close();

        if (!empty($failedWrites)) {
            $log[] = 'Write failures (' . count($failedWrites) . '): ' . implode(', ', array_slice($failedWrites, 0, 8));
            return [
                'ok' => false,
                'message' => 'File permission error while writing ' . count($failedWrites) . ' file(s) (first: ' . $failedWrites[0] . ').',
                'output' => implode("\n", $log),
                'before' => $beforeSha,
                'after' => $beforeSha,
                'status' => $preStatus,
            ];
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        if (isset($_SESSION['ppms_village_summary_cache'])) {
            unset($_SESSION['ppms_village_summary_cache']);
        }

        $newMeta = localneta_app_version(true);
        $state = localneta_vcs_load_state();
        if (is_array($targetCommit)) {
            $state['local_head_full'] = $targetCommit['full'];
            $state['local_head_short'] = $targetCommit['short'];
            $state['local_head_subject'] = $targetCommit['subject'];
            $state['local_head_date'] = $targetCommit['date'];
            $state['local_head_author'] = $targetCommit['author'];
        }
        $state['last_synced_at'] = date('Y-m-d H:i:s');
        $state['last_sync_mode'] = 'github_https';
        localneta_vcs_save_state($state);

        $log[] = "Synced via GitHub HTTPS ZipArchive: {$updatedCount} file(s) updated, {$unchangedCount} unchanged, {$skippedProtected} protected/ignored skipped.";
        $postStatus = localneta_vcs_status();
        $afterLabel = $postStatus['head_short'] ?: ('v' . ($newMeta['version'] ?? '1.2.1'));

        return [
            'ok' => true,
            'message' => 'Synced from GitHub HTTPS (' . $beforeSha . ' -> ' . $afterLabel . ', v' . ($newMeta['version'] ?? '1.2.1') . ', ' . $updatedCount . ' file(s) updated).',
            'output' => implode("\n", $log),
            'before' => $beforeSha,
            'after' => $afterLabel,
            'status' => $postStatus,
        ];
    } finally {
        if ($tmpFile && @is_file($tmpFile)) {
            @unlink($tmpFile);
        }
        if ($createdTmpDir) {
            @rmdir($repoRoot . DIRECTORY_SEPARATOR . 'tmp');
        }
    }
}

function localneta_vcs_fetch(): array {
    $status = localneta_vcs_status();
    if ($status['sync_mode'] === 'github_https') {
        return localneta_vcs_fetch_https();
    }
    if ($status['sync_mode'] !== 'git_cli') {
        return [
            'ok' => false,
            'message' => $status['caps']['reason'] ?? 'Git CLI and PHP HTTPS Sync are both unavailable on this server.',
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
    if ($status['sync_mode'] === 'github_https') {
        return localneta_vcs_pull_https();
    }
    if ($status['sync_mode'] !== 'git_cli') {
        return [
            'ok' => false,
            'message' => $status['caps']['reason'] ?? 'Git CLI and PHP HTTPS Sync are both unavailable on this server.',
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

    $newMeta = localneta_app_version(true);
    $postStatus = localneta_vcs_status();
    $afterSha = $postStatus['head_short'];

    return [
        'ok' => true,
        'message' => 'Updated LocalNeta from GitHub (' . ($beforeSha ?: 'init') . ' -> ' . $afterSha . ', v' . ($newMeta['version'] ?? '1.2.1') . ').',
        'output' => $combinedOut !== '' ? $combinedOut : 'Fast-forward pull completed.',
        'before' => $beforeSha,
        'after' => $afterSha,
        'status' => $postStatus,
    ];
}

function localneta_vcs_path_stats(string $relPath): array {
    $abs = localneta_repo_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relPath, '/'));
    if (@is_file($abs)) {
        return ['exists' => true, 'type' => 'file', 'count' => 1, 'bytes' => (int)@filesize($abs)];
    }
    if (@is_dir($abs)) {
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
    $caps = localneta_vcs_capabilities();
    $trackedList = [];
    $ignoredSet = [];

    if ($caps['cli_ready']) {
        $lsRes = localneta_git_exec(['ls-files'], 8);
        if ($lsRes['ok'] && $lsRes['stdout'] !== '') {
            foreach (preg_split('/\r?\n/', $lsRes['stdout']) ?: [] as $tf) {
                $trackedList[strtolower(trim($tf))] = true;
            }
        }

        $probePaths = [
            'includes/config.local.php',
            'ppms/includes/config.local.php',
            '.env',
            'database_backup.sql',
            'includes/mlc_seed.json',
            'data/MP_RS_2026.xls',
            'assets/uploads/avatars/avatar-sample.jpg',
            'logs/app.log',
        ];
        $chkRes = localneta_git_exec(array_merge(['check-ignore'], $probePaths), 5);
        if ($chkRes['stdout'] !== '') {
            foreach (preg_split('/\r?\n/', $chkRes['stdout']) ?: [] as $ig) {
                $ignoredSet[strtolower(trim($ig))] = true;
            }
        }
    } else {
        // On shared hosting without Git CLI, check .gitignore presence + hardcoded sync-guard protection
        $giPath = $root . DIRECTORY_SEPARATOR . '.gitignore';
        $giText = @is_file($giPath) ? (string)@file_get_contents($giPath) : '';
        if ($giText !== '') {
            foreach ([
                'includes/config.local.php' => '/includes/config.local.php',
                '.env' => '.env',
                'database_backup.sql' => '*.sql',
                'includes/mlc_seed.json' => '/includes/mlc_seed.json',
                'data/MP_RS_2026.xls' => '/data/',
                'assets/uploads/avatars/avatar-sample.jpg' => '/assets/uploads/',
                'logs/app.log' => '/logs/',
            ] as $pKey => $needle) {
                if (str_contains($giText, $needle) && localneta_vcs_is_protected_relpath($pKey)) {
                    $ignoredSet[strtolower($pKey)] = true;
                }
            }
        }
    }

    $sqlCount = 0;
    $sqlBytes = 0;
    try {
        $topItems = @scandir($root) ?: [];
        foreach ($topItems as $item) {
            if ($item === '.' || $item === '..' || $item === '.git') {
                continue;
            }
            $full = $root . DIRECTORY_SEPARATOR . $item;
            if (@is_file($full) && preg_match('/\.(?:sql|sql\.gz|sql\.bz2|sql\.xz|sql\.zip|sqlite|sqlite3|db|dump|bak)$/i', $item)) {
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
            'pattern' => '/includes/config.local.php, /includes/vcs_state.local.php',
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
            'pattern' => '/data/, *.xls, *.xlsx, *.pdf, *.csv',
            'probe' => 'data/MP_RS_2026.xls',
            'disk_path' => 'data',
            'reason' => 'Large ECI PDF notifications & official XLS/XLSX datasets.',
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
