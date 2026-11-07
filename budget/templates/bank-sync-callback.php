<?php
/**
 * Shown after the bank redirects back from an Enable Banking authorization.
 *
 * @var array{success: bool, message: string, backUrl: string} $_
 */
style('budget', 'style');
?>

<div id="bank-sync-callback" class="emptycontent">
    <h2><?php p($_['success'] ? $l->t('Bank connected') : $l->t('Bank connection failed')); ?></h2>
    <p><?php p($_['message']); ?></p>
    <p>
        <a href="<?php p($_['backUrl']); ?>" class="button primary"><?php p($l->t('Back to bank sync')); ?></a>
    </p>
</div>
