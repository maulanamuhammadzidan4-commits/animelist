<?php require_once __DIR__ . '/logic.php'; ?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#100b0d">
    <title><?= $selectedEpisode ? e($selectedEpisodeName) . ' — ' : ($selectedShowName ? e(displayTitle($selectedShowName)) . ' — ' : '') ?>AniList</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="topbar">
    <div class="shell nav">
        <a class="brand" href="index.php">
            <img class="brand-logo" src="logo.png" alt="Anime Library logo">
            <span>ANILIST<small>Local Anime Library Collection</small></span>
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
                <p class="autoplay-notice" id="autoplay-notice" role="status" hidden>Browser memblokir autoplay. Tekan tombol putar untuk melanjutkan.</p>
            </div>
        </div>
        <nav class="episode-navigation" aria-label="Episode navigation">
            <?php if ($previousEpisode !== null): ?>
                <a class="episode-nav-button" rel="prev" href="?watch=<?= rawurlencode($selectedCategory . '/' . $selectedShowName . '/' . $previousEpisode['name']) ?>">
                    <span aria-hidden="true">←</span> Previous episode
                </a>
            <?php else: ?>
                <span class="episode-nav-button disabled" aria-disabled="true">
                    <span aria-hidden="true">←</span> Previous episode
                </span>
            <?php endif; ?>
            <label class="auto-next-toggle">
                <input id="auto-next-toggle" type="checkbox" checked>
                <span>Auto next &amp; autoplay</span>
            </label>
            <?php if ($nextEpisode !== null): ?>
                <a class="episode-nav-button next" rel="next" href="?watch=<?= rawurlencode($selectedCategory . '/' . $selectedShowName . '/' . $nextEpisode['name']) ?>">
                    Next episode <span aria-hidden="true">→</span>
                </a>
            <?php else: ?>
                <span class="episode-nav-button next disabled" aria-disabled="true">
                    Next episode <span aria-hidden="true">→</span>
                </span>
            <?php endif; ?>
        </nav>
        <div class="section-head"><div><h2>Episodes<?= $selectedSeasonName !== null ? ' · ' . e(displayTitle($selectedSeasonName)) : '' ?></h2><p>Dua episode sebelumnya, episode aktif, lalu episode berikutnya</p></div><span class="count"><?= count($selectedAnime) ?> files</span></div>
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
            <p>Koleksi anime pribadi lokal yang telah dikumpulkan selama lebih dari satu tahun dapat diakses dengan mudah lewat local IP tanpa memerlukan koneksi internet, cukup koneksi yang sama, streaming dapat dilakukan</p>
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

        <?php foreach ($categorySections as $section): ?>
            <?php
                $category = $section['category'];
                $filtered = $section['shows'];
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
                        <?php foreach ($section['cards'] as $card): ?>
                            <?php
                                $showName = $card['name'];
                                $episodes = $card['episodes'];
                                $hasPoster = $card['hasPoster'];
                                $posterUrl = $card['posterUrl'];
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

<?php if ($selectedEpisode !== null): ?>
<script>
    const autoNextToggle = document.querySelector('#auto-next-toggle');
    const autoNextStorageKey = 'animelist.autoNextEnabled';
    const storedAutoNext = localStorage.getItem(autoNextStorageKey);
    autoNextToggle.checked = storedAutoNext !== 'false';
    autoNextToggle.addEventListener('change', () => {
        localStorage.setItem(autoNextStorageKey, String(autoNextToggle.checked));
    });

    const episodePlayer = document.querySelector('.player-wrap video');
    const nextEpisodeLink = document.querySelector('.episode-navigation a[rel="next"]');
    const autoplayNotice = document.querySelector('#autoplay-notice');

    if (new URLSearchParams(window.location.search).get('autoplay') === '1' && autoNextToggle.checked) {
        episodePlayer.play().catch((error) => {
            autoplayNotice.textContent = error.name === 'NotAllowedError'
                ? 'Browser memblokir autoplay. Tekan tombol putar untuk melanjutkan.'
                : 'Video tidak dapat diputar otomatis. Tekan tombol putar untuk mencoba.';
            autoplayNotice.hidden = false;
        });
    }

    if (nextEpisodeLink) {
        episodePlayer.addEventListener('ended', () => {
            if (!autoNextToggle.checked) return;
            const nextEpisodeUrl = new URL(nextEpisodeLink.href);
            nextEpisodeUrl.searchParams.set('autoplay', '1');
            window.location.assign(nextEpisodeUrl.href);
        }, { once: true });
    }
</script>
<?php endif; ?>

<footer class="shell">ANIME LIBRARY · PRIVATE LOCAL NETWORK · YOUR COLLECTION, YOUR CONTROL</footer>
</body>
</html>
