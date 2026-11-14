<section class="community-section" id="communityLive" aria-labelledby="communityTitle" data-hash="<?= zm_home_escape(zm_home_community_hash($homeCommunity)) ?>">
    <div class="community-top">
      <div>
        <div class="eyebrow">WSPÓLNY ARCHIWUM</div>
        <h2 id="communityTitle">Społeczność i najnowsze odkrycia</h2>
        <p class="community-copy">Zobacz, kto właśnie dołącza do archiwum, kto wnosi najwięcej, i zajrzyj do ostatnio opublikowanych kadrów z regionu.</p>
      </div>
      <!-- Bez href: zwykły link do fragmentu jest powodem, dla którego Chrome dopisuje
           #:~:text=… do adresu. Przewijanie robi skrypt w index.php (delegacja po data-scroll-to);
           role + tabindex dają dostęp z klawiatury (Enter / spacja). -->
  <a class="community-link" role="link" tabindex="0" data-scroll-to="galeria">przejdź do galerii ↑</a>
    </div>
    <div class="community-stats" role="group" aria-label="Statystyki archiwum">
      <div class="community-stat"><b><?= number_format((int) $homeCommunity['photos'], 0, ',', ' ') ?></b><span>opublikowanych zdjęć</span></div>
      <div class="community-stat"><b><?= number_format((int) $homeCommunity['users'], 0, ',', ' ') ?></b><span>osób w społeczności</span></div>
      <div class="community-stat"><b><?= number_format((int) $homeCommunity['categories'], 0, ',', ' ') ?></b><span>miejsc i kategorii</span></div>
    </div>
    <div class="community-grid">
      <article class="community-panel">
        <div class="community-panel-head"><h3>Najaktywniejsi</h3><span class="community-kicker">ranga wkładu</span></div>
        <?php if ($homeCommunity['contributors']): ?>
          <ol class="contrib-list">
            <?php foreach ($homeCommunity['contributors'] as $i => $c): ?>
              <?php
                $cName = trim((string) ($c['display_name'] ?? '')) ?: (string) $c['username'];
                $cInit = mb_strtoupper(mb_substr($cName, 0, 1));
                $cAvatar = zm_avatar_url($c, 96);
                $rank = zm_user_rank($pdo, $c);
                $rankName = (string) ($rank['name'] ?? '—');
                $cSeenMs = isset($c['last_seen']) && $c['last_seen'] !== null && $c['last_seen'] !== ''
                    ? (int) $c['last_seen'] : 0;
                $cOnline = ((int) ($c['show_online'] ?? 1) === 1)
                    && $cSeenMs > 0
                    && ((int) round(microtime(true) * 1000) - $cSeenMs) <= 600000;
              ?>
              <li class="contrib-card">
                <a class="contrib-card-link" href="<?= zm_home_escape(zm_profile_path((string) $c['username'], (int) $c['id'])) ?>" aria-label="Zobacz profil: <?= zm_home_escape($cName) ?>">
                  <span class="member-avatar" aria-hidden="true">
                    <?php if ($cAvatar !== ''): ?><img src="<?= zm_home_escape($cAvatar) ?>" alt=""><?php else: ?><?= zm_home_escape($cInit) ?><?php endif; ?>
                    <?php if ($cOnline): ?><span class="online-dot" title="Aktywny teraz"></span><?php endif; ?>
                  </span>
                  <div class="contrib-body">
                  <div class="contrib-top">
                    <!-- Jak punkty: numer rysuje CSS z atrybutu, więc w DOM nie ma
                         tekstu, którego system mógłby podświetlić. Numer jest
                         i tak czysto ozdobny (aria-hidden), bo pozycję i tak
                         wypowiada lista. -->
                    <span class="contrib-pos" aria-hidden="true" data-pos="<?= $i + 1 ?>"></span>
                    <!-- Nazwa prowadzi do profilu. Awatar celowo NIE jest linkiem:
                         link na awatarze sprawiał, że dotknięcie „w okolicy
                         użytkownika” wyglądało jak kliknięcie całej karty.
                         Teraz klikalne jest dokładnie to, co podświetla się po
                         najechaniu — sama nazwa. Bez podkreślenia, bo
                         .community-panel blokuje zaznaczanie i podświetlenie
                         dotyku, więc nie wychodzi efekt „linka”. -->
                    <span class="member-name contrib-name"><?= zm_home_escape($cName) ?></span>
                    <!-- Liczba punktów NIE jest w DOM jako tekst — rysuje ją CSS
                         z atrybutu data-pts. Dzięki temu system nie ma czego
                         zaznaczyć ani „wygoogluć”: to jedyny sposób, żeby
                         zablokować podświetlenie z poziomu telefonu, bo
                         user-select i tap-highlight nie wystarczają.
                         Dla czytników ekranu wartość zostaje w aria-label. -->
                    <span class="contrib-points" role="img" data-pts="<?= number_format((int) $c['points'], 0, ',', ' ') ?> pkt" aria-label="<?= number_format((int) $c['points'], 0, ',', ' ') ?> punktów za wkład w archiwum"></span>
                  </div>
                  <div class="contrib-meta">
                    <span class="contrib-rank" title="Ranga"><?= zm_home_escape($rankName) ?></span>
                    <?php if ((int) $c['photo_count'] > 0): ?>
                      <span class="contrib-chip" title="Zdjęcia w archiwum"><b><?= (int) $c['photo_count'] ?></b> zdjęć</span>
                    <?php endif; ?>
                    <?php if ((int) $c['likes_got'] > 0): ?>
                      <span class="contrib-chip" title="Otrzymane like"><b><?= (int) $c['likes_got'] ?></b> like</span>
                    <?php endif; ?>
                    <?php if ((int) $c['photo_count'] === 0 && (int) $c['likes_got'] === 0): ?>
                      <span class="contrib-chip contrib-chip-muted" title="Jeszcze nie ma wkładu">początkujący</span>
                    <?php endif; ?>
                  </div>
                  </div>
                </a>
              </li>
            <?php endforeach; ?>
          </ol>
        <?php else: ?>
          <p class="community-empty">Ranking pojawi się, gdy użytkownicy z publicznymi profilami zaczną dodawać zdjęcia.</p>
        <?php endif; ?>
      </article>
      <article class="community-panel">
        <div class="community-panel-head"><h3>Świeżo w archiwum</h3><span class="community-kicker">ostatnie publikacje</span></div>
        <?php if ($homeCommunity['photosLatest']): ?>
          <div class="latest-photos">
            <?php foreach ($homeCommunity['photosLatest'] as $photo): ?>
              <?php
                $photoTitle = function_exists('zm_photo_locality') ? zm_photo_locality($photo) : '';
                if ($photoTitle === '') {
                    $photoTitle = trim((string) ($photo['title'] ?? '')) ?: (string) $photo['filename'];
                }
              ?>
              <a class="latest-photo" href="index.php?photo=p<?= (int) $photo['id'] ?>" title="<?= zm_home_escape($photoTitle) ?>">
                <img src="<?= zm_home_escape(zm_thumb_url('p' . (int) $photo['id'], 240)) ?>" srcset="<?= zm_home_escape(zm_thumb_srcset('p' . (int) $photo['id'], [160, 240, 320, 480])) ?>" sizes="auto, (max-width:760px) 30vw, 200px" alt="<?= zm_home_escape($photoTitle) ?>" loading="lazy" decoding="async">
                <span class="latest-photo-label"><?= zm_home_escape($photoTitle) ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="community-empty">Pierwsze opublikowane zdjęcia pojawią się w tym miejscu.</p>
        <?php endif; ?>
      </article>
    </div>
    <div class="community-grid community-grid-3">
      <article class="community-panel">
        <div class="community-panel-head"><h3>Najczęściej fotografowane miejsca</h3><span class="community-kicker">według kadrów</span></div>
        <?php if ($homeCommunity['topPlaces']): ?>
          <ul class="place-list">
            <?php foreach ($homeCommunity['topPlaces'] as $place): ?>
              <?php
                $maxCount = (int) $homeCommunity['topPlaces'][0]['photo_count'];
                $pct = $maxCount > 0 ? max(8, (int) round((int) $place['photo_count'] / $maxCount * 100)) : 8;
              ?>
              <li class="place-row">
                <div class="place-folder-tree">
                  <button type="button" class="place-name place-gmina-folder" data-open-folder="<?= zm_home_escape($place['name']) ?>" title="Otwórz folder gminy: <?= zm_home_escape($place['name']) ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.5 6.5a2 2 0 0 1 2-2h4l2 2h7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/></svg>
                    <span><?= zm_home_escape($place['name']) ?></span>
                  </button>
                  <?php if (!empty($place['localities'])): ?>
                    <div class="place-locality-folders" aria-label="Miejscowości w gminie <?= zm_home_escape($place['name']) ?>">
                      <?php foreach ($place['localities'] as $locality): ?>
                        <div class="place-locality-folder">
                          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3.5 6.5a2 2 0 0 1 2-2h4l2 2h7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/></svg>
                          <span><?= zm_home_escape($locality) ?></span>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
                <span class="place-bar"><i style="width:<?= $pct ?>%"></i></span>
                <span class="place-count"><?= (int) $place['photo_count'] ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="community-empty">Gdy pojawią się kadry w kategoriach, zobaczymy tu najczęściej fotografowane miejsca.</p>
        <?php endif; ?>
      </article>
      <article class="community-panel">
        <div class="community-panel-head"><h3>Najbardziej lubiane kadry</h3><span class="community-kicker">polubienia</span></div>
        <?php if ($homeCommunity['topPhotos']): ?>
          <div class="top-photos">
            <?php foreach ($homeCommunity['topPhotos'] as $tp): ?>
              <?php
                $tpTitle = function_exists('zm_photo_locality') ? zm_photo_locality($tp) : '';
                if ($tpTitle === '') {
                    $tpTitle = trim((string) ($tp['title'] ?? '')) ?: ('kadr ' . (int) $tp['id']);
                }
              ?>
              <?php
                $tpUploaderName = trim((string) ($tp['uploader_display_name'] ?? ''));
                if ($tpUploaderName === '') {
                    $tpUploaderName = trim((string) ($tp['uploader_username'] ?? ''));
                }
              ?>
              <div class="top-photo">
                <a class="top-photo-image" href="index.php?photo=p<?= (int) $tp['id'] ?>" title="<?= zm_home_escape($tpTitle) ?>">
                  <img src="<?= zm_home_escape(zm_thumb_url('p' . (int) $tp['id'], 240)) ?>" srcset="<?= zm_home_escape(zm_thumb_srcset('p' . (int) $tp['id'], [160, 240, 320])) ?>" sizes="auto, 68px" alt="<?= zm_home_escape($tpTitle) ?>" loading="lazy" decoding="async">
                </a>
                <span class="top-photo-meta">
                  <a class="top-photo-title" href="index.php?photo=p<?= (int) $tp['id'] ?>" title="<?= zm_home_escape($tpTitle) ?>"><?= zm_home_escape($tpTitle) ?></a>
                  <?php if ($tpUploaderName !== '' && !empty($tp['uploader_id']) && !empty($tp['uploader_username'])): ?>
                    <a class="top-photo-author" href="<?= zm_home_escape(zm_profile_path((string) $tp['uploader_username'], (int) $tp['uploader_id'])) ?>" title="Zobacz profil użytkownika <?= zm_home_escape($tpUploaderName) ?>"><?= zm_home_escape($tpUploaderName) ?></a>
                  <?php elseif ($tpUploaderName !== ''): ?>
                    <span class="top-photo-author"><?= zm_home_escape($tpUploaderName) ?></span>
                  <?php endif; ?>
                  <span class="top-photo-likes">♥ <?= (int) $tp['like_count'] ?></span>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="community-empty">Gdy pojawią się polubienia, tu wylądują najpopularniejsze kadry.</p>
        <?php endif; ?>
      </article>
      <article class="community-panel">
        <div class="community-panel-head"><h3>Ostatnio dołączyli</h3><button type="button" class="community-more" data-open-directory>wszyscy użytkownicy →</button></div>
        <?php if ($homeCommunity['members']): ?>
          <div class="member-list">
            <?php foreach ($homeCommunity['members'] as $member): ?>
              <?php
                $name = trim((string) ($member['display_name'] ?? '')) ?: (string) $member['username'];
                $initial = mb_strtoupper(mb_substr($name, 0, 1));
                $avatar = zm_avatar_url($member, 96);
                $joinedAt = strtotime((string) ($member['created_at'] ?? ''));
                $mSeenMs = isset($member['last_seen']) && $member['last_seen'] !== null && $member['last_seen'] !== ''
                    ? (int) $member['last_seen'] : 0;
                $mOnline = ((int) ($member['show_online'] ?? 1) === 1)
                    && $mSeenMs > 0
                    && ((int) round(microtime(true) * 1000) - $mSeenMs) <= 600000;
              ?>
              <a class="member-card" href="<?= zm_home_escape(zm_profile_path((string) $member['username'], (int) $member['id'])) ?>" aria-label="Zobacz profil: <?= zm_home_escape($name) ?>">
                <span class="member-avatar"><?php if ($avatar !== ''): ?><img src="<?= zm_home_escape($avatar) ?>" alt=""><?php else: ?><?= zm_home_escape($initial) ?><?php endif; ?><?php if ($mOnline): ?><span class="online-dot" title="Aktywny teraz"></span><?php endif; ?></span>
                <span class="member-text"><span class="member-name"><?= zm_home_escape($name) ?></span></span>
                <span class="member-date"><?= $joinedAt ? 'od ' . date('d.m.Y', $joinedAt) : '' ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="community-empty">Publiczne profile pojawią się tutaj, gdy użytkownicy zdecydują się je udostępnić.</p>
        <?php endif; ?>
      </article>
    </div>
    <div class="community-activity" role="group" aria-label="Aktywność społeczności w ostatnim tygodniu">
      <div class="community-panel-head"><h3>Aktywność w ostatnim tygodniu</h3><span class="community-kicker">7 dni</span></div>
      <div class="activity-row">
        <div class="activity-cell"><b><?= (int) $homeCommunity['photosWeek'] ?></b><span>nowych zdjęć</span></div>
        <div class="activity-cell"><b><?= (int) $homeCommunity['likesWeek'] ?></b><span>polubień</span></div>
        <div class="activity-cell"><b><?= (int) $homeCommunity['membersWeek'] ?></b><span>nowych osób</span></div>
      </div>
      <p class="community-note">Liczby dotyczą tylko zdjęć i kont publicznych — prywatne profile pozostają niewidoczne.</p>
    </div>
  </section>
