<?php
declare(strict_types=1);

/*
 * ANIME LIBRARY — single-file local streaming interface
 * Place this file at /srv/http/animelist/index.php
 * Anime folders are expected at /srv/http/animelist/anime/{end,ongoing}/{Title}/ with optional nested season folders and poster.png
 */

$animeRoot = __DIR__ . '/anime';
$categories = ['ongoing' => 'Ongoing', 'end' => 'Completed'];
$videoExtensions = ['mp4', 'm4v', 'webm', 'ogv', 'ogg', 'mkv', 'avi', 'mov'];

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function displayTitle(string $value): string {
    // Only prettify the visible title; keep the real folder name for filesystem paths and URLs.
    return preg_replace('/_+/', ' ', $value) ?? $value;
}

function urlPath(array $segments): string {
    // Encode each path segment separately so nested season folders keep their slashes.
    return implode('/', array_map(
        fn($segment) => implode('/', array_map('rawurlencode', explode('/', str_replace('\\', '/', (string)$segment)))),
        $segments
    ));
}

function formatBytes(int|false $bytes): string {
    if ($bytes === false || $bytes <= 0) return 'Unknown size';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $size = (float)$bytes;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return number_format($size, $i === 0 ? 0 : 1) . ' ' . $units[$i];
}

function scanLibrary(string $root, array $categories, array $extensions): array {
    $library = [];
    foreach ($categories as $category => $label) {
        $categoryPath = $root . DIRECTORY_SEPARATOR . $category;
        if (!is_dir($categoryPath) || !is_readable($categoryPath)) {
            $library[$category] = [];
            continue;
        }

        $shows = scandir($categoryPath) ?: [];
        $library[$category] = [];
        foreach ($shows as $show) {
            if ($show === '.' || $show === '..' || str_starts_with($show, '.')) continue;
            $showPath = $categoryPath . DIRECTORY_SEPARATOR . $show;
            if (!is_dir($showPath) || !is_readable($showPath)) continue;

            // Scan recursively so folders such as Season 1/ and Season 2/ are included.
            // getSubPathname() keeps the relative folder path needed to stream each file.
            $episodes = [];
            $directory = new RecursiveDirectoryIterator($showPath, FilesystemIterator::SKIP_DOTS);
            $iterator = new RecursiveIteratorIterator($directory);
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile() || $fileInfo->isLink()) continue;

                $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $iterator->getSubPathname());
                $parts = explode('/', $relativePath);
                $hiddenPath = false;
                foreach ($parts as $part) {
                    if (str_starts_with($part, '.')) { $hiddenPath = true; break; }
                }
                if ($hiddenPath) continue;

                $extension = strtolower(pathinfo($fileInfo->getFilename(), PATHINFO_EXTENSION));
                if (in_array($extension, $extensions, true)) {
                    $episodes[] = [
                        'name' => $relativePath,
                        'display' => $relativePath,
                        'size' => $fileInfo->getSize(),
                        'extension' => $extension,
                    ];
                }
            }

            usort($episodes, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
            $library[$category][$show] = $episodes;
        }

        uksort($library[$category], 'strnatcasecmp');
    }
    return $library;
}

$library = scanLibrary($animeRoot, $categories, $videoExtensions);

// Treat immediate child folders containing videos as selectable seasons/parts.
$seasonGroups = [];
foreach ($library as $category => $shows) {
    foreach ($shows as $showName => $episodes) {
        $groups = [];
        $rootEpisodes = [];
        foreach ($episodes as $episode) {
            $relative = str_replace('\\', '/', $episode['name']);
            if (str_contains($relative, '/')) {
                [$seasonName] = explode('/', $relative, 2);
                $groups[$seasonName][] = $episode;
            } else {
                $rootEpisodes[] = $episode;
            }
        }
        if ($rootEpisodes && $groups) $groups['Other Episodes'] = $rootEpisodes;
        if ($groups) {
            uksort($groups, 'strnatcasecmp');
            $seasonGroups[$category][$showName] = $groups;
        }
    }
}
$totalShows = 0;
$totalEpisodes = 0;
foreach ($library as $shows) {
    $totalShows += count($shows);
    foreach ($shows as $episodes) $totalEpisodes += count($episodes);
}

$selectedAnime = null;
$selectedCategory = null;
$selectedEpisode = null;
$selectedShowName = null;
$selectedEpisodeName = null;
$selectedSeasonName = null;
$errorMessage = '';

if (isset($_GET['anime'])) {
    $parts = explode('/', (string)$_GET['anime'], 2);
    if (count($parts) === 2 && isset($library[$parts[0]]) && array_key_exists($parts[1], $library[$parts[0]])) {
        $selectedCategory = $parts[0];
        $selectedShowName = $parts[1];
        $selectedAnime = $library[$selectedCategory][$selectedShowName];
    } else {
        $errorMessage = 'Anime tidak ditemukan di koleksi.';
    }
}

if (isset($_GET['season'])) {
    $parts = explode('/', (string)$_GET['season'], 3);
    if (count($parts) === 3
        && isset($library[$parts[0]][$parts[1]])
        && isset($seasonGroups[$parts[0]][$parts[1]][$parts[2]])) {
        $selectedCategory = $parts[0];
        $selectedShowName = $parts[1];
        $selectedSeasonName = $parts[2];
        $selectedAnime = $seasonGroups[$selectedCategory][$selectedShowName][$selectedSeasonName];
    } else {
        $errorMessage = 'Season atau part tidak ditemukan.';
    }
}

if (isset($_GET['watch'])) {
    $parts = explode('/', (string)$_GET['watch'], 3);
    if (count($parts) === 3 && isset($library[$parts[0]]) && array_key_exists($parts[1], $library[$parts[0]])) {
        $found = null;
        foreach ($library[$parts[0]][$parts[1]] as $episode) {
            if ($episode['name'] === $parts[2]) {
                $found = $episode;
                break;
            }
        }
        if ($found !== null) {
            $selectedCategory = $parts[0];
            $selectedShowName = $parts[1];
            $selectedEpisodeName = $parts[2];
            $selectedEpisode = $found;
            $selectedAnime = $library[$selectedCategory][$selectedShowName];
            if (isset($seasonGroups[$selectedCategory][$selectedShowName])) {
                $episodeParts = explode('/', str_replace('\\', '/', $selectedEpisodeName), 2);
                if (count($episodeParts) === 2 && isset($seasonGroups[$selectedCategory][$selectedShowName][$episodeParts[0]])) {
                    $selectedSeasonName = $episodeParts[0];
                    $selectedAnime = $seasonGroups[$selectedCategory][$selectedShowName][$selectedSeasonName];
                } elseif (isset($seasonGroups[$selectedCategory][$selectedShowName]['Other Episodes'])) {
                    $selectedSeasonName = 'Other Episodes';
                    $selectedAnime = $seasonGroups[$selectedCategory][$selectedShowName][$selectedSeasonName];
                }
            }
        } else {
            $errorMessage = 'Episode tidak ditemukan di koleksi.';
        }
    } else {
        $errorMessage = 'Alamat episode tidak valid.';
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$activeCategory = (string)($_GET['category'] ?? 'all');
if (!in_array($activeCategory, ['all', 'ongoing', 'end'], true)) $activeCategory = 'all';

$videoUrl = '';
if ($selectedEpisode !== null && $selectedCategory !== null && $selectedShowName !== null) {
    $videoUrl = 'anime/' . urlPath([$selectedCategory, $selectedShowName, $selectedEpisodeName]);
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#100b0d">
    <title><?= $selectedEpisode ? e($selectedEpisodeName) . ' — ' : ($selectedShowName ? e(displayTitle($selectedShowName)) . ' — ' : '') ?>Anime Library</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #100b0d;
            --panel: #191114;
            --panel-2: #21161a;
            --line: #39242b;
            --text: #f6edf0;
            --muted: #b39da5;
            --red: #dc143c;
            --red-soft: #ff4968;
            --gold: #d4af37;
            --radius: 18px;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0; min-height: 100vh; color: var(--text);
            background:
                radial-gradient(ellipse at 12% -10%, rgba(220,20,60,.18), transparent 36rem),
                radial-gradient(ellipse at 100% 15%, rgba(212,175,55,.08), transparent 30rem),
                var(--bg);
            font: 15px/1.55 Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        a { color: inherit; text-decoration: none; }
        button, input { font: inherit; }
        .shell { width: min(1180px, calc(100% - 36px)); margin: 0 auto; }
        .topbar {
            position: sticky; top: 0; z-index: 20; backdrop-filter: blur(18px);
            background: rgba(16,11,13,.82); border-bottom: 1px solid rgba(255,255,255,.06);
        }
        .nav { min-height: 72px; display: flex; align-items: center; justify-content: space-between; gap: 22px; }
        .brand { display: inline-flex; align-items: center; gap: 11px; font-weight: 850; letter-spacing: .04em; }
        .brand-logo {
            display: block; width: 46px; height: 46px; object-fit: contain;
            filter: drop-shadow(0 5px 14px rgba(220,20,60,.22));
        }
        .brand small { display: block; color: var(--muted); font-size: 9px; letter-spacing: .22em; font-weight: 700; }
        .nav-right { display: flex; align-items: center; gap: 18px; color: var(--muted); font-size: 13px; }
        .status { display: inline-flex; align-items: center; gap: 7px; color: #d7c4ca; }
        .dot { width: 7px; height: 7px; background: #54d69a; border-radius: 50%; box-shadow: 0 0 12px #54d69a; }
        main { padding: 34px 0 70px; }
        .hero {
            position: relative; isolation: isolate; overflow: hidden; padding: clamp(25px, 5vw, 54px);
            min-height: 310px; display: flex; flex-direction: column; justify-content: center;
            border: 1px solid #4a2832; border-radius: 26px;
            background:
                linear-gradient(90deg, rgba(16,8,12,.98) 0%, rgba(25,9,15,.91) 42%, rgba(25,9,15,.30) 100%),
                url("bg.png") right center / auto 125% no-repeat,
                linear-gradient(115deg, #4b0c1e, #201117 56%, #2a1f13);
            box-shadow: 0 24px 70px rgba(0,0,0,.2);
        }
        .hero:after {
            content: "時"; position: absolute; z-index: -1; right: 5%; top: -68px;
            font-size: clamp(180px, 30vw, 340px); line-height: 1;
            color: rgba(212,175,55,.075); pointer-events: none; font-family: serif;
        }
        .eyebrow { color: var(--gold); font-size: 11px; font-weight: 800; letter-spacing: .24em; text-transform: uppercase; }
        h1 { max-width: 680px; margin: 12px 0 12px; font-size: clamp(34px, 6vw, 60px); line-height: 1.04; letter-spacing: -.045em; }
        .hero p { max-width: 570px; color: #d0bbc2; margin: 0; }
        .stats { display: flex; flex-wrap: wrap; gap: 25px; margin-top: 27px; }
        .stat strong { display: block; font-size: 22px; }
        .stat span { color: var(--muted); font-size: 11px; letter-spacing: .08em; text-transform: uppercase; }
        .toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px; margin: 30px 0 22px; }
        .tabs { display: flex; gap: 7px; padding: 5px; border: 1px solid var(--line); border-radius: 999px; background: rgba(25,17,20,.8); }
        .tab { padding: 9px 16px; border-radius: 999px; color: var(--muted); font-size: 13px; font-weight: 700; }
        .tab.active { background: var(--red); color: white; box-shadow: 0 5px 18px rgba(220,20,60,.22); }
        .search { display: flex; width: min(100%, 330px); gap: 8px; }
        .search input {
            width: 100%; min-width: 0; padding: 11px 15px; border: 1px solid var(--line);
            border-radius: 999px; color: var(--text); background: var(--panel); outline: none;
        }
        .search input:focus { border-color: var(--red); }
        .section-head { display: flex; align-items: end; justify-content: space-between; gap: 12px; margin: 28px 0 15px; }
        .section-head h2 { margin: 0; font-size: 21px; letter-spacing: -.02em; }
        .section-head p { margin: 3px 0 0; color: var(--muted); font-size: 12px; }
        .count { color: var(--gold); font-size: 12px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(205px, 1fr)); gap: 14px; }
        .anime-card {
            display: flex; flex-direction: column; min-height: 166px; padding: 17px;
            border: 1px solid var(--line); border-radius: var(--radius);
            background: linear-gradient(145deg, rgba(35,23,28,.96), rgba(23,16,19,.96));
            background-position: center; background-size: cover; background-repeat: no-repeat;
            transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease;
        }
        .anime-card.has-poster {
            background-position: center 30%;
            box-shadow: inset 0 -100px 75px -28px rgba(12,7,10,.94), inset 0 0 0 1000px rgba(12,7,10,.12);
        }
        .anime-card.has-poster .folder-icon,
        .anime-card.has-poster .pill { backdrop-filter: blur(8px); }
        .anime-card.has-poster h3,
        .anime-card.has-poster .card-meta { text-shadow: 0 2px 8px rgba(0,0,0,.95); }
        .anime-card:hover { transform: translateY(-3px); border-color: #8d344b; box-shadow: 0 12px 28px rgba(0,0,0,.2); }
        .card-top { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
        .folder-icon { width: 38px; height: 38px; display: grid; place-items: center; border-radius: 12px; background: rgba(220,20,60,.13); color: #ff7189; font-size: 19px; }
        .pill { padding: 4px 8px; border-radius: 999px; color: var(--gold); background: rgba(212,175,55,.1); font-size: 10px; font-weight: 800; }
        .anime-card h3 { margin: 18px 0 7px; font-size: 15px; line-height: 1.35; }
        .card-meta { margin-top: auto; display: flex; justify-content: space-between; color: var(--muted); font-size: 11px; }
        .empty, .notice {
            padding: 22px; border: 1px solid var(--line); border-radius: var(--radius);
            color: var(--muted); background: var(--panel);
        }
        .notice { margin-bottom: 18px; border-color: rgba(212,175,55,.3); color: #e7d9a8; }
        .back { display: inline-flex; margin-bottom: 20px; color: var(--muted); font-size: 13px; }
        .back:hover { color: white; }
        .detail-head {
            position: relative; isolation: isolate; overflow: hidden;
            min-height: 230px; display: flex; flex-direction: column; justify-content: center;
            padding: clamp(25px, 5vw, 38px); border: 1px solid #4a2832;
            border-radius: var(--radius); background-color: var(--panel);
            background-position: center 30%; background-size: cover; background-repeat: no-repeat;
            box-shadow: 0 18px 50px rgba(0,0,0,.18);
        }
        .detail-head h1 { font-size: clamp(27px, 4vw, 42px); margin: 9px 0; overflow-wrap: anywhere; text-shadow: 0 2px 12px rgba(0,0,0,.85); }
        .detail-head p { text-shadow: 0 2px 10px rgba(0,0,0,.9); }
        .episode-list { display: grid; gap: 9px; }
        .episode {
            display: flex; align-items: center; justify-content: space-between; gap: 15px;
            padding: 14px 16px; border: 1px solid var(--line); border-radius: 13px; background: var(--panel);
        }
        .episode:hover { border-color: #8d344b; background: var(--panel-2); }
        .episode-name { min-width: 0; overflow-wrap: anywhere; font-weight: 650; font-size: 13px; }
        .episode-size { flex-shrink: 0; color: var(--muted); font-size: 11px; }
        .play-icon { color: var(--red-soft); margin-right: 9px; }
        .player-wrap { overflow: hidden; border: 1px solid var(--line); border-radius: 20px; background: #050405; }
        video { display: block; width: 100%; max-height: 72vh; aspect-ratio: 16/9; background: #050405; }
        .player-info { padding: 19px 21px; background: var(--panel); }
        .player-info h1 { margin: 4px 0 8px; font-size: clamp(22px, 4vw, 34px); overflow-wrap: anywhere; }
        .player-info p { margin: 0; color: var(--muted); font-size: 12px; }
        .compat { margin-top: 14px; padding: 12px 14px; border-radius: 12px; color: #e7d9a8; background: rgba(212,175,55,.08); font-size: 12px; }
        footer { padding: 24px 0 35px; border-top: 1px solid rgba(255,255,255,.06); color: #806d74; font-size: 11px; text-align: center; }
        @media (max-width: 620px) {
            .shell { width: min(100% - 24px, 1180px); }
            .nav { min-height: 62px; }
            .nav-right .status { display: none; }
            main { padding-top: 20px; }
            .hero {
                min-height: 330px; border-radius: 20px;
                background:
                    linear-gradient(90deg, rgba(16,8,12,.96), rgba(25,9,15,.70)),
                    url("bg.png") 68% center / auto 100% no-repeat,
                    linear-gradient(115deg, #4b0c1e, #201117);
            }
            .toolbar { align-items: stretch; }
            .tabs { width: fit-content; }
            .search { width: 100%; }
            .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; }
            .anime-card { min-height: 158px; padding: 13px; }
            .anime-card h3 { font-size: 13px; }
            .episode { padding: 12px; }
        }
    </style>
</head>
<body>
<header class="topbar">
    <div class="shell nav">
        <a class="brand" href="index.php">
            <img class="brand-logo" src="logo.png" alt="Anime Library logo">
            <span>ANIME LIBRARY<small>LOCAL STREAMING COLLECTION</small></span>
        </a>
        <div class="nav-right">
            <span class="status"><i class="dot"></i> LOCAL SERVER</span>
            <span><?= number_format($totalEpisodes) ?> episodes</span>
        </div>
    </div>
</header>

<main class="shell">
    <?php if ($errorMessage !== ''): ?>
        <div class="notice"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($selectedEpisode !== null): ?>
        <a class="back" href="<?= $selectedSeasonName !== null ? '?season=' . rawurlencode($selectedCategory . '/' . $selectedShowName . '/' . $selectedSeasonName) : '?anime=' . rawurlencode($selectedCategory . '/' . $selectedShowName) ?>">← Back to <?= e(displayTitle($selectedShowName)) ?><?= $selectedSeasonName !== null ? ' / ' . e(displayTitle($selectedSeasonName)) : '' ?></a>
        <div class="player-wrap">
            <video controls playsinline preload="metadata">
                <source src="<?= e($videoUrl) ?>">
                Browser ini tidak dapat memutar format video tersebut.
            </video>
            <div class="player-info">
                <div class="eyebrow"><?= e($categories[$selectedCategory]) ?> / <?= e(displayTitle($selectedShowName)) ?><?= $selectedSeasonName !== null ? ' / ' . e(displayTitle($selectedSeasonName)) : '' ?></div>
                <h1><?= e($selectedEpisode['display'] ?? $selectedEpisodeName) ?></h1>
                <p><?= e(strtoupper($selectedEpisode['extension'])) ?> · <?= e(formatBytes($selectedEpisode['size'])) ?></p>
                <?php if (in_array($selectedEpisode['extension'], ['mkv', 'avi', 'mov'], true)): ?>
                    <div class="compat">Catatan: dukungan MKV, AVI, dan beberapa MOV bergantung pada browser serta codec di dalam file. Jika video tidak tampil, coba buka file MP4/WebM atau gunakan pemutar eksternal seperti VLC.</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="section-head"><div><h2>Episodes<?= $selectedSeasonName !== null ? ' · ' . e(displayTitle($selectedSeasonName)) : '' ?></h2><p>Dua episode sebelumnya, episode aktif, lalu episode berikutnya</p></div><span class="count"><?= count($selectedAnime) ?> files</span></div>
        <?php
            // Rotate the list so up to two previous episodes appear first,
            // the currently playing episode is next, and the remaining list wraps to episode 1.
            $currentIndex = array_search($selectedEpisodeName, array_column($selectedAnime, 'name'), true);
            $startIndex = ($currentIndex === false) ? 0 : max(0, $currentIndex - 2);
            $orderedEpisodes = array_merge(
                array_slice($selectedAnime, $startIndex),
                array_slice($selectedAnime, 0, $startIndex)
            );
        ?>
        <div class="episode-list">
            <?php foreach ($orderedEpisodes as $episode): ?>
                <?php $isCurrent = $episode['name'] === $selectedEpisodeName; ?>
                <a class="episode" href="?watch=<?= rawurlencode($selectedCategory . '/' . $selectedShowName . '/' . $episode['name']) ?>">
                    <span class="episode-name"><span class="play-icon"><?= $isCurrent ? '▶' : '▷' ?></span><?= e($episode['display'] ?? $episode['name']) ?></span>
                    <span class="episode-size"><?= e(formatBytes($episode['size'])) ?></span>
                </a>
            <?php endforeach; ?>
        </div>

    <?php elseif ($selectedAnime !== null): ?>
        <a class="back" href="index.php">← Back to library</a>
        <?php
            $detailPosterFile = $animeRoot . '/' . $selectedCategory . '/' . $selectedShowName . '/poster.png';
            $hasDetailPoster = is_file($detailPosterFile) && is_readable($detailPosterFile);
            $detailPosterUrl = 'anime/' . urlPath([$selectedCategory, $selectedShowName, 'poster.png']);
            if ($hasDetailPoster) $detailPosterUrl .= '?v=' . (string)filemtime($detailPosterFile);
        ?>
        <section class="detail-head"
            <?php if ($hasDetailPoster): ?>
                style="background-image: linear-gradient(90deg, rgba(16,8,12,.96) 0%, rgba(20,9,14,.82) 55%, rgba(20,9,14,.35) 100%), url('<?= e($detailPosterUrl) ?>');"
            <?php else: ?>
                style="background-image: linear-gradient(115deg, rgba(75,12,30,.92), rgba(32,17,23,.94) 56%, rgba(42,31,19,.90));"
            <?php endif; ?>>
            <div class="eyebrow"><?= e($categories[$selectedCategory]) ?> COLLECTION</div>
            <h1><?= e(displayTitle($selectedShowName)) ?></h1>
            <p style="color:#e0cfd5;margin:0"><?= count($selectedAnime) ?> video files in this folder</p>
        </section>
        <?php if (isset($seasonGroups[$selectedCategory][$selectedShowName]) && $selectedSeasonName === null): ?>
            <?php $showSeasons = $seasonGroups[$selectedCategory][$selectedShowName]; ?>
            <div class="section-head">
                <div><h2>Seasons & Parts</h2><p>Pilih season atau part yang ingin ditonton</p></div>
                <span class="count"><?= count($showSeasons) ?> seasons / parts</span>
            </div>
            <div class="episode-list">
                <?php foreach ($showSeasons as $seasonName => $seasonEpisodes): ?>
                    <a class="episode" href="?season=<?= rawurlencode($selectedCategory . '/' . $selectedShowName . '/' . $seasonName) ?>">
                        <span class="episode-name"><span class="play-icon">▷</span><?= e(displayTitle($seasonName)) ?></span>
                        <span class="episode-size"><?= count($seasonEpisodes) ?> episodes</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <?php if ($selectedSeasonName !== null): ?>
                <a class="back" href="?anime=<?= rawurlencode($selectedCategory . '/' . $selectedShowName) ?>">← All seasons / parts</a>
            <?php endif; ?>
            <div class="section-head">
                <div><h2>Episode List<?= $selectedSeasonName !== null ? ' · ' . e(displayTitle($selectedSeasonName)) : '' ?></h2><p>Pilih video untuk mulai menonton</p></div>
                <span class="count"><?= count($selectedAnime) ?> episodes</span>
            </div>
            <?php if (!$selectedAnime): ?>
                <div class="empty">Belum ada file video yang dikenali di folder ini.</div>
            <?php else: ?>
                <div class="episode-list">
                    <?php foreach ($selectedAnime as $episode): ?>
                        <a class="episode" href="?watch=<?= rawurlencode($selectedCategory . '/' . $selectedShowName . '/' . $episode['name']) ?>">
                            <span class="episode-name"><span class="play-icon">▷</span><?= e($episode['display'] ?? $episode['name']) ?></span>
                            <span class="episode-size"><?= e(formatBytes($episode['size'])) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    <?php else: ?>
        <section class="hero">
            <div class="eyebrow">YOUR PERSONAL ANIME SERVER</div>
            <h1>One library.<br><span style="color:var(--red-soft)">Your own world.</span></h1>
            <p>Koleksi anime pribadi yang bisa diakses melalui jaringan lokal. Pilih seri, buka episode, lalu nikmati tontonan dari browser.</p>
            <div class="stats">
                <div class="stat"><strong><?= number_format($totalShows) ?></strong><span>Anime titles</span></div>
                <div class="stat"><strong><?= number_format($totalEpisodes) ?></strong><span>Video files</span></div>
                <div class="stat"><strong>2</strong><span>Collections</span></div>
            </div>
        </section>

        <div class="toolbar">
            <nav class="tabs" aria-label="Filter collection">
                <a class="tab <?= $activeCategory === 'all' ? 'active' : '' ?>" href="index.php">All</a>
                <a class="tab <?= $activeCategory === 'ongoing' ? 'active' : '' ?>" href="?category=ongoing">Ongoing</a>
                <a class="tab <?= $activeCategory === 'end' ? 'active' : '' ?>" href="?category=end">Completed</a>
            </nav>
            <form class="search" method="get" action="index.php">
                <?php if ($activeCategory !== 'all'): ?><input type="hidden" name="category" value="<?= e($activeCategory) ?>"><?php endif; ?>
                <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search anime titles..." aria-label="Search anime titles">
            </form>
        </div>

        <?php
        $categoriesToShow = $activeCategory === 'all' ? array_keys($categories) : [$activeCategory];
        $shownCount = 0;
        foreach ($categoriesToShow as $category):
            $shows = $library[$category] ?? [];
            $filtered = [];
            foreach ($shows as $showName => $episodes) {
                if ($search !== '' && mb_stripos($showName, $search, 0, 'UTF-8') === false) continue;
                $filtered[$showName] = $episodes;
            }
            if ($activeCategory === 'all' && $search === '' && !$filtered) continue;
            $shownCount += count($filtered);
        ?>
            <section>
                <div class="section-head">
                    <div><h2><?= e($categories[$category]) ?></h2><p><?= $category === 'end' ? 'Finished series in your collection' : 'Series currently in progress' ?></p></div>
                    <span class="count"><?= count($filtered) ?> titles</span>
                </div>
                <?php if (!$filtered): ?>
                    <div class="empty"><?= $search !== '' ? 'Tidak ada judul yang cocok dengan pencarian.' : 'Belum ada folder anime di kategori ini.' ?></div>
                <?php else: ?>
                    <div class="grid">
                        <?php foreach ($filtered as $showName => $episodes): ?>
                            <?php
                                $posterFile = $animeRoot . '/' . $category . '/' . $showName . '/poster.png';
                                $posterUrl = 'anime/' . urlPath([$category, $showName, 'poster.png']);
                                $hasPoster = is_file($posterFile) && is_readable($posterFile);
                                if ($hasPoster) $posterUrl .= '?v=' . (string)filemtime($posterFile);
                            ?>
                            <a class="anime-card<?= $hasPoster ? ' has-poster' : '' ?>"
                               href="?anime=<?= rawurlencode($category . '/' . $showName) ?>"
                               <?php if ($hasPoster): ?>style="background-image: linear-gradient(180deg, rgba(16,8,12,.48) 0%, rgba(16,8,12,.18) 35%, rgba(12,7,10,.96) 100%), url('<?= e($posterUrl) ?>');"<?php endif; ?>>
                                <div class="card-top"><span class="folder-icon">▣</span><span class="pill"><?= e($categories[$category]) ?></span></div>
                                <h3><?= e(displayTitle($showName)) ?></h3>
                                <div class="card-meta"><span><?php if (isset($seasonGroups[$category][$showName])): ?><?= count($seasonGroups[$category][$showName]) ?> seasons / parts<?php else: ?><?= count($episodes) ?> episodes<?php endif; ?></span><span>Open series ↗</span></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
        <?php if ($shownCount === 0 && $search !== ''): ?>
            <div class="empty">Tidak ada anime yang cocok dengan “<?= e($search) ?>”.</div>
        <?php endif; ?>
    <?php endif; ?>
</main>

<footer class="shell">ANIME LIBRARY · PRIVATE LOCAL NETWORK · YOUR COLLECTION, YOUR CONTROL</footer>
</body>
</html>
