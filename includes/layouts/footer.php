<?php
declare(strict_types=1);

function render_page_footer(string $context = 'public'): void
{
    $isPublic = str_starts_with($context, 'public');
    ?>
<footer class="site-footer<?php echo $isPublic ? ' site-footer-public' : ' site-footer-app'; ?>">
    <div class="container">
        <div class="footer-main">
            <a class="site-brand footer-brand" href="<?php echo e(APP_URL); ?>/" aria-label="FeesPortal home">
                <span class="brand-mark" aria-hidden="true"><i class="bi bi-mortarboard-fill"></i></span>
                <span class="brand-name">FeesPortal<small>Blue Eyes Infosoft</small></span>
            </a>
            <p>A centralized fee management platform for schools, coaching institutes, sports academies and training centres.</p>
            <?php if ($isPublic): ?>
                <nav class="footer-links" aria-label="Footer navigation">
                    <?php $footerRoot = $context === 'public' ? '' : APP_URL . '/'; ?>
                    <a href="<?php echo e($footerRoot . '#panels'); ?>">Panels</a><a href="<?php echo e($footerRoot . '#features'); ?>">Features</a><a href="<?php echo e($footerRoot . '#admission'); ?>">Admissions</a><a href="<?php echo e($footerRoot . '#security'); ?>">Security</a>
                </nav>
            <?php endif; ?>
        </div>
        <div class="footer-bottom">
            <span>&copy; <?php echo date('Y'); ?> Blue Eyes Infosoft Pvt. Ltd.</span>
            <span>FeesPortal</span>
        </div>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
<script src="<?php echo e(APP_URL); ?>/public/assets/js/app.js" defer></script>
</body>
</html>
<?php
}
