<?php

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var array<int,array<string,string>> $cards cards already filtered for the current user */
if (empty($cards)) {
    return;
}
?>
<ul class="uxs-cp-cards">
<?php foreach ($cards as $card) : ?>
    <?php
    $status = in_array($card['status'], ['ok', 'warn', 'bad'], true) ? $card['status'] : '';
    $class  = 'uxs-cp-card' . ($status !== '' ? ' uxs-cp-card--' . $status : '') . ' uxs-cp-card--' . htmlspecialchars($card['kind'], ENT_QUOTES, 'UTF-8');
    $url    = $card['url'];
    // Validated when saved: http(s) URL, absolute path or "index.php?..." (relative to the administrator entry point).
    $href   = $url;
    $external = $url !== '' && preg_match('#^https?://#i', $url) === 1;
    ?>
    <li class="d-flex">
    <?php if ($href !== '') : ?>
        <a class="<?php echo $class; ?> w-100" href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $external ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
    <?php else : ?>
        <div class="<?php echo $class; ?> w-100">
    <?php endif; ?>
            <span class="uxs-cp-card__title"><?php echo htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if ($card['value'] !== '') : ?>
                <span class="uxs-cp-card__value"><?php echo htmlspecialchars($card['value'], ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
            <?php $text = $card['text'] !== '' ? $card['text'] : $card['body']; ?>
            <?php if ($text !== '') : ?>
                <span class="uxs-cp-card__text"><?php echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
            <?php if ($card['kind'] === 'link') : ?>
                <span class="uxs-cp-card__more"><?php echo $external ? Text::_('MOD_UXSTUDIO_DASHBOARD_OPEN_EXTERNAL') : Text::_('MOD_UXSTUDIO_DASHBOARD_OPEN'); ?></span>
            <?php endif; ?>
    <?php if ($href !== '') : ?>
        </a>
    <?php else : ?>
        </div>
    <?php endif; ?>
    </li>
<?php endforeach; ?>
</ul>
