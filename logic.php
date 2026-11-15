<?php
declare(strict_types=1);

/*
 * ANIME LIBRARY — local streaming interface
 * Keep this file and index.php in the web root.
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

$orderedEpisodes = [];
if ($selectedEpisode !== null && $selectedAnime !== null) {
    // Keep up to two previous episodes first, then wrap the remaining list.
    $currentIndex = array_search($selectedEpisodeName, array_column($selectedAnime, 'name'), true);
    $startIndex = ($currentIndex === false) ? 0 : max(0, $currentIndex - 2);
    $orderedEpisodes = array_merge(
        array_slice($selectedAnime, $startIndex),
        array_slice($selectedAnime, 0, $startIndex)
    );
}

$detailPosterFile = '';
$detailPosterUrl = '';
$hasDetailPoster = false;
if ($selectedAnime !== null && $selectedCategory !== null && $selectedShowName !== null) {
    $detailPosterFile = $animeRoot . '/' . $selectedCategory . '/' . $selectedShowName . '/poster.png';
    $hasDetailPoster = is_file($detailPosterFile) && is_readable($detailPosterFile);
    $detailPosterUrl = 'anime/' . urlPath([$selectedCategory, $selectedShowName, 'poster.png']);
    if ($hasDetailPoster) $detailPosterUrl .= '?v=' . (string)filemtime($detailPosterFile);
}

$categorySections = [];
$shownCount = 0;
$categoriesToShow = $activeCategory === 'all' ? array_keys($categories) : [$activeCategory];
foreach ($categoriesToShow as $category) {
    $shows = $library[$category] ?? [];
    $filtered = [];
    foreach ($shows as $showName => $episodes) {
        if ($search !== '' && mb_stripos($showName, $search, 0, 'UTF-8') === false) continue;
        $filtered[$showName] = $episodes;
    }

    if ($activeCategory === 'all' && $search === '' && !$filtered) continue;

    $cards = [];
    foreach ($filtered as $showName => $episodes) {
        $posterFile = $animeRoot . '/' . $category . '/' . $showName . '/poster.png';
        $hasPoster = is_file($posterFile) && is_readable($posterFile);
        $posterUrl = 'anime/' . urlPath([$category, $showName, 'poster.png']);
        if ($hasPoster) $posterUrl .= '?v=' . (string)filemtime($posterFile);
        $cards[] = [
            'name' => $showName,
            'episodes' => $episodes,
            'hasPoster' => $hasPoster,
            'posterUrl' => $posterUrl,
        ];
    }

    $shownCount += count($filtered);
    $categorySections[] = [
        'category' => $category,
        'shows' => $filtered,
        'cards' => $cards,
    ];
}
?>
