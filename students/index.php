<?php
require __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/platform_settings.php';
require __DIR__ . '/inc/student_auth.php';

no_cache_headers();
student_redirect_if_logged_in('account.php');

if (!function_exists('h')) {
  function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
  }
}

$row = get_platform_settings_row($pdo);

/* =========================
   Hero
   ========================= */
$heroSmallTitle = trim((string)($row['hero_small_title'] ?? ''));
$heroTitle = trim((string)($row['hero_title'] ?? ''));
$heroDescription = trim((string)($row['hero_description'] ?? ''));
$heroButtonText = trim((string)($row['hero_button_text'] ?? ''));
$heroButtonUrl = trim((string)($row['hero_button_url'] ?? ''));
$heroTeacherImageDb = trim((string)($row['hero_teacher_image'] ?? ''));

$heroTeacherImageUrl = null;
if ($heroTeacherImageDb !== '') {
  $heroTeacherImageUrl = student_public_asset_url($heroTeacherImageDb);
}

$hasHero =
  ($heroSmallTitle !== '') ||
  ($heroTitle !== '') ||
  ($heroDescription !== '') ||
  ($heroButtonText !== '') ||
  ($heroTeacherImageUrl !== null);

/* =========================
   Stats
   ========================= */
$heroStatsBgText = trim((string)($row['hero_stats_bg_text'] ?? 'ENGLISH'));

$heroStat1Value = trim((string)($row['hero_stat_1_value'] ?? ''));
$heroStat1Label = trim((string)($row['hero_stat_1_label'] ?? ''));

$heroStat2Value = trim((string)($row['hero_stat_2_value'] ?? ''));
$heroStat2Label = trim((string)($row['hero_stat_2_label'] ?? ''));

$heroStat3Value = trim((string)($row['hero_stat_3_value'] ?? ''));
$heroStat3Label = trim((string)($row['hero_stat_3_label'] ?? ''));

$hasStats =
  ($heroStat1Value !== '' || $heroStat1Label !== '') ||
  ($heroStat2Value !== '' || $heroStat2Label !== '') ||
  ($heroStat3Value !== '' || $heroStat3Label !== '');

/* =========================
   Feature cards
   ========================= */
$featureCardsEnabled = (int)($row['feature_cards_enabled'] ?? 1);
$featureCardsTitle = trim((string)($row['feature_cards_title'] ?? ''));

$featureCards = [];
if ($featureCardsEnabled === 1) {
  try {
    $featureCards = $pdo->query("
      SELECT id, theme, icon_path, title, body
      FROM platform_feature_cards
      WHERE is_active=1
      ORDER BY sort_order ASC, id ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $featureCards = [];
  }
}
$hasFeatureCards = ($featureCardsEnabled === 1) && (count($featureCards) > 0);

/* =========================
   Grades (from admin -> uploads/grades)
   ========================= */
$grades = [];
try {
  $grades = $pdo->query("
    SELECT id, name, image_path
    FROM grades
    WHERE is_active=1
    ORDER BY sort_order ASC, id ASC
  ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $grades = [];
}
$hasGrades = count($grades) > 0;

/* =========================
   CTA banner (under grades)
   ========================= */
$ctaEnabled = (int)($row['cta_enabled'] ?? 1);
$ctaTitle = trim((string)($row['cta_title'] ?? ''));
$ctaSubtitle = trim((string)($row['cta_subtitle'] ?? ''));
$ctaBtnText = trim((string)($row['cta_button_text'] ?? ''));
$ctaBtnUrl = trim((string)($row['cta_button_url'] ?? ''));

$hasCta = ($ctaEnabled === 1) && ($ctaTitle !== '' || $ctaSubtitle !== '' || ($ctaBtnText !== '' && $ctaBtnUrl !== ''));

/* =========================
   Footer (from settings)
   ========================= */
$footerEnabled = (int)($row['footer_enabled'] ?? 1);

$footerLogoDb = trim((string)($row['footer_logo_path'] ?? ''));
$footerLogoUrl = null;
if ($footerLogoDb !== '') $footerLogoUrl = student_public_asset_url($footerLogoDb);

$footerSocialTitle = trim((string)($row['footer_social_title'] ?? 'السوشيال ميديا'));
$footerContactTitle = trim((string)($row['footer_contact_title'] ?? 'تواصل معنا'));
$footerPhone1 = trim((string)($row['footer_phone_1'] ?? ''));
$footerPhone2 = trim((string)($row['footer_phone_2'] ?? ''));
$footerRights = trim((string)($row['footer_rights_line'] ?? ''));
$footerDev = trim((string)($row['footer_developed_by_line'] ?? ''));

$footerSocials = [];
if ($footerEnabled === 1) {
  try {
    $footerSocials = $pdo->query("
      SELECT label, url, icon_path
      FROM platform_footer_social_links
      WHERE is_active=1
      ORDER BY sort_order ASC, id ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $footerSocials = [];
  }
}

$hasFooter = ($footerEnabled === 1) && (
  $footerLogoUrl !== null ||
  $footerSocialTitle !== '' ||
  $footerContactTitle !== '' ||
  $footerPhone1 !== '' ||
  $footerPhone2 !== '' ||
  $footerRights !== '' ||
  $footerDev !== '' ||
  count($footerSocials) > 0
);

function footer_icon_svg(string $key): string {
  $key = strtolower(trim($key));
  return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm7.9 9h-3.2a15.7 15.7 0 0 0-1.2-5A8.1 8.1 0 0 1 19.9 11zM12 4c.8 1 1.7 2.8 2.2 7H9.8c.5-4.2 1.4-6 2.2-7zM4.1 13h3.2a15.7 15.7 0 0 0 1.2 5A8.1 8.1 0 0 1 4.1 13zm3.2-2H4.1A8.1 8.1 0 0 1 8.5 6a15.7 15.7 0 0 0-1.2 5zm2.5 2h4.4c-.5 4.2-1.4 6-2.2 7c-.8-1-1.7-2.8-2.2-7zm5.7 5a15.7 15.7 0 0 0 1.2-5h3.2a8.1 8.1 0 0 1-4.4 5z"/></svg>';
}

?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;800;900;1000&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="assets/css/site.css">
  <link rel="stylesheet" href="assets/css/header.css">
  <link rel="stylesheet" href="assets/css/hero.css">
  <link rel="stylesheet" href="assets/css/hero-stats.css">
  <link rel="stylesheet" href="assets/css/feature-cards.css">
  <link rel="stylesheet" href="assets/css/grades-section.css">
  <link rel="stylesheet" href="assets/css/cta-banner.css">
  <link rel="stylesheet" href="assets/css/footer.css">

  <title>الطلاب</title>
</head>
<body>

  <?php require __DIR__ . '/inc/header.php'; ?>

  <?php if ($hasHero): ?>
    <section class="hero">
      <div class="container">
        <div class="hero__grid">

          <div class="hero__content">
            <?php if ($heroSmallTitle !== ''): ?>
              <div class="hero__small"><?php echo h($heroSmallTitle); ?></div>
            <?php endif; ?>

            <?php if ($heroTitle !== ''): ?>
              <h1 class="hero__title"><?php echo nl2br(h($heroTitle)); ?></h1>
            <?php endif; ?>

            <?php if ($heroDescription !== ''): ?>
              <p class="hero__desc"><?php echo nl2br(h($heroDescription)); ?></p>
            <?php endif; ?>

            <?php if ($heroButtonText !== '' && $heroButtonUrl !== ''): ?>
              <div class="hero__actions">
                <a class="hero__btn" href="<?php echo h($heroButtonUrl); ?>">
                  <?php echo h($heroButtonText); ?>
                </a>
              </div>
            <?php endif; ?>
          </div>

          <div class="hero__media">
            <?php if ($heroTeacherImageUrl): ?>
              <div class="hero__imgWrap">
                <img class="hero__img" src="<?php echo h($heroTeacherImageUrl); ?>" alt="Teacher">
              </div>
            <?php endif; ?>
          </div>

        </div>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($hasStats): ?>
    <section class="hero-stats" aria-label="إحصائيات">
      <div class="container">
        <div class="hero-stats__wrap">
          <div class="hero-stats__bg" aria-hidden="true"><?php echo h($heroStatsBgText !== '' ? $heroStatsBgText : 'ENGLISH'); ?></div>

          <div class="hero-stats__card">
            <div class="hero-stats__grid">

              <div class="hero-stats__item">
                <div class="hero-stats__val js-counter"
                     data-target="<?php echo h($heroStat1Value); ?>"
                     aria-label="<?php echo h($heroStat1Value); ?>">0</div>
                <div class="hero-stats__lbl"><?php echo h($heroStat1Label); ?></div>
              </div>

              <div class="hero-stats__item">
                <div class="hero-stats__val js-counter"
                     data-target="<?php echo h($heroStat2Value); ?>"
                     aria-label="<?php echo h($heroStat2Value); ?>">0</div>
                <div class="hero-stats__lbl"><?php echo h($heroStat2Label); ?></div>
              </div>

              <div class="hero-stats__item">
                <div class="hero-stats__val js-counter"
                     data-target="<?php echo h($heroStat3Value); ?>"
                     aria-label="<?php echo h($heroStat3Value); ?>">0</div>
                <div class="hero-stats__lbl"><?php echo h($heroStat3Label); ?></div>
              </div>

            </div>
          </div>

        </div>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($hasFeatureCards): ?>
    <section class="feature-cards" aria-label="ليه تختار؟">
      <div class="container">
        <?php if ($featureCardsTitle !== ''): ?>
          <h2 class="feature-cards__title"><?php echo h($featureCardsTitle); ?></h2>
        <?php endif; ?>

        <div class="feature-cards__grid">
          <?php foreach ($featureCards as $c): ?>
            <?php
              $theme = ((string)($c['theme'] ?? 'light') === 'dark') ? 'dark' : 'light';
              $iconDb = trim((string)($c['icon_path'] ?? ''));
              $iconUrl = null;
              if ($iconDb !== '') $iconUrl = student_public_asset_url($iconDb);
            ?>
            <article class="fc-card fc-card--<?php echo h($theme); ?>">
              <?php if ($iconUrl): ?>
                <div class="fc-card__icon">
                  <img src="<?php echo h($iconUrl); ?>" alt="">
                </div>
              <?php endif; ?>

              <h3 class="fc-card__h"><?php echo h((string)$c['title']); ?></h3>

              <?php if (!empty($c['body'])): ?>
                <p class="fc-card__p"><?php echo nl2br(h((string)$c['body'])); ?></p>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($hasGrades): ?>
    <section class="grades-sec" aria-label="الصفوف الدراسية">
      <div class="container">
        <header class="grades-sec__head">
          <h2 class="grades-sec__title">الصفوف الدراسية</h2>
          <p class="grades-sec__subtitle">اختار صفك الدراسي واكتشف المنهج المصمم خصيصاً لك</p>
        </header>

        <div class="grades-grid">
          <?php foreach ($grades as $g): ?>
            <?php
              $imgDb = trim((string)($g['image_path'] ?? ''));
              $imgUrl = null;
              if ($imgDb !== '') $imgUrl = student_public_asset_url($imgDb);
              $name = (string)($g['name'] ?? '');
              $goUrl = 'register.php';
            ?>
            <article class="grade-cardx">

              <div class="grade-cardx__mediaLink">
                <?php if ($imgUrl): ?>
                  <img class="grade-cardx__img" src="<?php echo h($imgUrl); ?>" alt="<?php echo h($name); ?>">
                <?php else: ?>
                  <div class="grade-cardx__img" style="display:grid;place-items:center;height:240px;color:var(--muted);font-weight:1000;">بدون صورة</div>
                <?php endif; ?>
                <div class="grade-cardx__overlay" aria-hidden="true"></div>
              </div>

              <div class="grade-cardx__body">
                <div class="grade-cardx__name"><?php echo h($name); ?></div>

                <div class="grade-cardx__actions">
                  <a class="grade-cardx__btn" href="<?php echo h($goUrl); ?>">
                    ابدأ الآن <span class="arr">‹</span>
                  </a>
                </div>
              </div>

            </article>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($hasCta): ?>
    <section class="cta-banner" aria-label="Call To Action">
      <div class="container">
        <div class="cta-banner__box">
          <?php if ($ctaTitle !== ''): ?>
            <h2 class="cta-banner__title"><?php echo h($ctaTitle); ?></h2>
          <?php endif; ?>

          <?php if ($ctaSubtitle !== ''): ?>
            <p class="cta-banner__subtitle"><?php echo h($ctaSubtitle); ?></p>
          <?php endif; ?>

          <?php if ($ctaBtnText !== '' && $ctaBtnUrl !== ''): ?>
            <a class="cta-banner__btn" href="<?php echo h($ctaBtnUrl); ?>"><?php echo h($ctaBtnText); ?></a>
          <?php endif; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($hasFooter): ?>
    <footer class="site-footer" aria-label="Footer">
      <div class="container">
        <div class="footer__grid">

          <div class="footer__col footer__col--left">
            <?php if ($footerLogoUrl): ?>
              <img class="footer__logo" src="<?php echo h($footerLogoUrl); ?>" alt="Logo">
            <?php else: ?>
              <div class="footer__logoFallback" aria-hidden="true"></div>
            <?php endif; ?>
          </div>

          <div class="footer__col footer__col--mid">
            <?php if ($footerSocialTitle !== ''): ?>
              <div class="footer__title"><?php echo h($footerSocialTitle); ?></div>
            <?php endif; ?>

            <?php if (!empty($footerSocials)): ?>
              <ul class="footer__list">
                <?php foreach ($footerSocials as $s): ?>
                  <?php
                    $socIconDb = trim((string)($s['icon_path'] ?? ''));
                    $socIconUrl = null;
                    if ($socIconDb !== '') $socIconUrl = student_public_asset_url($socIconDb);
                  ?>
                  <li class="footer__item">
                    <a class="footer__link" href="<?php echo h((string)$s['url']); ?>" target="_blank" rel="noopener">
                      <span class="footer__ico" aria-hidden="true">
                        <?php if ($socIconUrl): ?>
                          <img class="footer__icoImg" src="<?php echo h($socIconUrl); ?>" alt="">
                        <?php else: ?>
                          <?php echo footer_icon_svg('website'); ?>
                        <?php endif; ?>
                      </span>
                      <span class="footer__lbl"><?php echo h((string)$s['label']); ?></span>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>

          <div class="footer__col footer__col--mid2">
            <?php if ($footerContactTitle !== ''): ?>
              <div class="footer__title"><?php echo h($footerContactTitle); ?></div>
            <?php endif; ?>

            <div class="footer__phones">
              <?php if ($footerPhone1 !== ''): ?><div class="footer__phone"><?php echo h($footerPhone1); ?></div><?php endif; ?>
              <?php if ($footerPhone2 !== ''): ?><div class="footer__phone"><?php echo h($footerPhone2); ?></div><?php endif; ?>
            </div>
          </div>

          <div class="footer__col footer__col--right">
            <?php if ($footerRights !== ''): ?>
              <div class="footer-copy footer-copy--rights">
                <?php echo h($footerRights); ?>
              </div>
            <?php endif; ?>

            <?php if ($footerDev !== ''): ?>
              <div class="footer-copy footer-copy--dev">
                <?php echo h($footerDev); ?>
              </div>
            <?php endif; ?>
          </div>

        </div>
      </div>
    </footer>
  <?php endif; ?>

  <script src="assets/js/theme.js"></script>
  <script src="assets/js/counters.js"></script>
  <script src="assets/js/grades-tap.js"></script>
</body>
</html>
