<?php

declare(strict_types=1);

/**
 * Modern HTML rendering helpers for the GitInstall web UI.
 */

function resolveDashboardView(mixed $raw): string
{
    $allowed = ['home', 'updates', 'environment', 'databases', 'install-uuid', 'installer', 'system'];
    $value = is_string($raw) ? $raw : '';

    return in_array($value, $allowed, true) ? $value : 'home';
}

function resolveInstallerTab(mixed $raw): string
{
    return ('tags' === $raw) ? 'tags' : 'branches';
}

/**
 * @return array{view: string, itab: string|null}
 */
function resolveDashboardState(mixed $getView, mixed $getItab = null, mixed $postView = null, mixed $postItab = null): array
{
    $viewSource = is_string($postView) ? $postView : $getView;
    $view = resolveDashboardView($viewSource);
    if ('installer' !== $view) {
        return ['view' => $view, 'itab' => null];
    }

    $itabSource = is_string($postItab) ? $postItab : $getItab;

    return ['view' => $view, 'itab' => resolveInstallerTab($itabSource)];
}

function buildDashboardViewHref(string $view, ?string $itab = null): string
{
    $params = ['_t' => time()];

    if ('home' !== $view) {
        $params['view'] = $view;
        if ('installer' === $view) {
            $params['itab'] = resolveInstallerTab($itab);
        }
    }

    return '?'.http_build_query($params);
}

function renderDashboardStateInputs(string $view, ?string $itab = null): string
{
    if ('home' === $view) {
        return '';
    }

    $inputs = '<input type="hidden" name="view" value="'.htmlspecialchars($view).'">';
    if ('installer' === $view) {
        $inputs .= '<input type="hidden" name="itab" value="'.htmlspecialchars(resolveInstallerTab($itab)).'">';
    }

    return $inputs;
}

/**
 * @param list<array{value: string, label: string}> $options
 */
function renderDropdown(string $name, array $options, string $selectedValue = '', bool $autoSubmit = false, string $extraClass = '', bool $disabled = false): string
{
    $selectedLabel = '';
    $hasSelected = false;
    foreach ($options as $option) {
        if ($option['value'] === $selectedValue) {
            $selectedLabel = $option['label'];
            $hasSelected = true;

            break;
        }
    }

    if (!$hasSelected && [] !== $options) {
        $selectedValue = $options[0]['value'];
        $selectedLabel = $options[0]['label'];
    }

    if ([] === $options) {
        $selectedValue = '';
        $selectedLabel = '-';
        $disabled = true;
    }

    $optionsHtml = '';
    foreach ($options as $option) {
        $isSelected = $option['value'] === $selectedValue;
        $optionsHtml .= '<li class="dropdown-option'.($isSelected ? ' is-selected' : '').'" role="option" data-value="'.htmlspecialchars($option['value']).'" aria-selected="'.($isSelected ? 'true' : 'false').'">'.htmlspecialchars($option['label']).'</li>';
    }

    $classAttr = htmlspecialchars(trim('dropdown '.$extraClass.($disabled ? ' is-disabled' : '')));
    $autoAttr = ($autoSubmit && !$disabled) ? ' data-autosubmit="1"' : '';
    $disabledAttr = $disabled ? ' disabled aria-disabled="true"' : '';

    return '<div class="'.$classAttr.'"'.$autoAttr.'>'
        .'<input type="hidden" name="'.htmlspecialchars($name).'" value="'.htmlspecialchars($selectedValue).'">'
        .'<button type="button" class="dropdown-toggle" aria-haspopup="listbox" aria-expanded="false"'.$disabledAttr.'>'
        .'<span class="dropdown-label">'.htmlspecialchars($selectedLabel).'</span>'
        .'<svg class="dropdown-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>'
        .'</button>'
        .'<ul class="dropdown-menu" role="listbox" tabindex="-1">'.$optionsHtml.'</ul>'
        .'</div>';
}

function lucideIcon(string $name, int $size = 16): string
{
    /** @var array<string, string> $map */
    static $map = [
        'home' => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'refresh-cw' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        'settings' => '<path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915"/><circle cx="12" cy="12" r="3"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/>',
        'fingerprint' => '<path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4"/><path d="M14 13.12c0 2.38 0 6.38-1 8.88"/><path d="M17.29 21.02c.12-.6.43-2.3.5-3.02"/><path d="M2 12a10 10 0 0 1 18-6"/><path d="M2 16h.01"/><path d="M21.8 16c.2-2 .131-5.354 0-6"/><path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2"/><path d="M8.65 22c.21-.66.45-1.32.57-2"/><path d="M9 6.8a6 6 0 0 1 9 5.2v2"/>',
        'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.106-3.105c.32-.322.863-.22.983.218a6 6 0 0 1-8.259 7.057l-7.91 7.91a1 1 0 0 1-2.999-3l7.91-7.91a6 6 0 0 1 7.057-8.259c.438.12.54.662.219.984z"/>',
        'download' => '<path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'trash-2' => '<path d="M10 11v6"/><path d="M14 11v6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'save' => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
        'play' => '<path d="M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'log-in' => '<path d="m10 17 5-5-5-5"/><path d="M15 12H3"/><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>',
        'log-out' => '<path d="m16 17 5-5-5-5"/><path d="M21 12H9"/><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>',
        'external-link' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'arrow-right' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'git-branch' => '<path d="M15 6a9 9 0 0 0-9 9V3"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/>',
        'tag' => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
        'github' => '<path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/>',
        'hard-drive' => '<line x1="22" y1="12" x2="2" y2="12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11"/><line x1="6" y1="16" x2="6.01" y2="16"/><line x1="10" y1="16" x2="10.01" y2="16"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        'folder' => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
        'endpoint' => '<rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/>',
        'code' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
        'puzzle' => '<path d="M19.439 7.85c-.049.322.059.648.289.878l1.568 1.568c.47.47.706 1.087.706 1.704s-.235 1.233-.706 1.704l-1.611 1.611a.98.98 0 0 1-.837.276c-.47-.07-.802-.48-.968-.925a2.501 2.501 0 1 0-3.214 3.214c.446.166.855.497.925.968a.979.979 0 0 1-.276.837l-1.61 1.61a2.404 2.404 0 0 1-1.705.707 2.402 2.402 0 0 1-1.704-.706l-1.568-1.568a1.026 1.026 0 0 0-.877-.29c-.493.074-.84.504-1.02.968a2.5 2.5 0 1 1-3.237-3.237c.464-.18.894-.527.967-1.02a1.026 1.026 0 0 0-.289-.877l-1.568-1.568a2.402 2.402 0 0 1-.706-1.704c0-.617.236-1.234.706-1.704L4.81 9.475a.98.98 0 0 1 .837-.276c.47.07.802.48.967.925a2.501 2.501 0 1 0 3.214-3.214c-.445-.166-.854-.497-.925-.968a.98.98 0 0 1 .276-.837l1.611-1.611A2.405 2.405 0 0 1 11.485 2.1c.617 0 1.234.236 1.704.706l1.568 1.568c.23.23.556.338.877.29.493-.075.84-.504 1.02-.969a2.5 2.5 0 1 1 3.237 3.237c-.464.18-.894.527-.967 1.02Z"/>',
        'upload-cloud' => '<path d="M4 14.899A7 7 0 1 1 15 21h-1"/><path d="M12 12v9"/><path d="m16 16-4-4-4 4"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'memory-stick' => '<path d="M6 19v-3"/><path d="M10 19v-3"/><path d="M14 19v-3"/><path d="M18 19v-3"/><path d="M8 7V5"/><path d="M16 7V5"/><path d="M12 7V5"/><path d="M12 12V8"/><rect width="20" height="14" x="2" y="3" rx="2"/>',
        'archive' => '<rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
        'sparkles' => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/>',
    ];

    $inner = $map[$name] ?? '';
    if ('' === $inner) {
        return '';
    }

    return '<svg width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$inner.'</svg>';
}

function renderModal(string $modalId, string $title, string $bodyHtml, string $closeLabel = 'Close'): string
{
    $idAttr = htmlspecialchars($modalId);

    return '<div class="modal" id="'.$idAttr.'" role="dialog" aria-modal="true" aria-hidden="true">'
        .'<div class="modal-backdrop" data-modal-close="'.$idAttr.'"></div>'
        .'<div class="modal-dialog" role="document">'
        .'<div class="modal-header">'
        .'<h3 class="modal-title">'.htmlspecialchars($title).'</h3>'
        .'<button type="button" class="modal-close" data-modal-close="'.$idAttr.'" aria-label="'.htmlspecialchars($closeLabel).'">'.lucideIcon('x', 16).'</button>'
        .'</div>'
        .'<div class="modal-body">'.$bodyHtml.'</div>'
        .'</div></div>';
}

function renderConfirmAttributes(string $title, string $message, string $submitLabel): string
{
    return ' data-confirm-title="'.htmlspecialchars($title).'"'
        .' data-confirm-message="'.htmlspecialchars($message).'"'
        .' data-confirm-submit-label="'.htmlspecialchars($submitLabel).'"';
}

function renderConfirmationModal(string $modalId, string $closeLabel): string
{
    $idAttr = htmlspecialchars($modalId);
    $closeLabelEscaped = htmlspecialchars($closeLabel);

    return '<div class="modal modal-confirm" id="'.$idAttr.'" role="dialog" aria-modal="true" aria-hidden="true">'
        .'<div class="modal-backdrop" data-modal-close="'.$idAttr.'"></div>'
        .'<div class="modal-dialog" role="document">'
        .'<div class="modal-header">'
        .'<h3 class="modal-title" data-confirm-title></h3>'
        .'<button type="button" class="modal-close" data-modal-close="'.$idAttr.'" aria-label="'.$closeLabelEscaped.'">'.lucideIcon('x', 16).'</button>'
        .'</div>'
        .'<div class="modal-body">'
        .'<p class="confirm-message" data-confirm-message></p>'
        .'<div class="modal-actions">'
        .'<button type="button" class="btn btn-secondary" data-modal-close="'.$idAttr.'">'.lucideIcon('x', 14).' '.$closeLabelEscaped.'</button>'
        .'<button type="button" class="btn" data-confirm-submit>'.lucideIcon('check', 14).' </button>'
        .'</div>'
        .'</div>'
        .'</div>'
        .'</div>';
}

function formatVersionBadge(string $version): string
{
    if ('' === $version || 'unknown' === $version) {
        return '<span class="status-badge">unknown</span>';
    }

    return '<span class="status-badge">'.htmlspecialchars($version).'</span>';
}

/**
 * @param array<string, string>           $lang
 */
function renderWelcomeBox(string $title, string $subtitle, array $quickLinks, array $lang): string
{
    $linksHtml = '';
    foreach ($quickLinks as $link) {
        $label = htmlspecialchars((string) ($link['label'] ?? ''));
        $href = htmlspecialchars((string) ($link['href'] ?? '#'));
        $iconKey = (string) ($link['icon'] ?? 'arrow-right');
        $iconSvg = lucideIcon($iconKey, 14);
        $external = !empty($link['external']);
        $targetAttr = $external ? ' target="_blank" rel="noopener noreferrer"' : '';
        $linksHtml .= '<a class="welcome-link" href="'.$href.'"'.$targetAttr.'>'.$iconSvg.' <span>'.$label.'</span></a>';
    }

    return '<section class="welcome-card">'
        .'<div class="welcome-card-glow" aria-hidden="true"></div>'
        .'<div class="welcome-card-body">'
        .'<div class="welcome-card-icon" aria-hidden="true">'.lucideIcon('github', 22).'</div>'
        .'<div class="welcome-card-content">'
        .'<h3 class="welcome-card-title">'.htmlspecialchars($title).'</h3>'
        .'<p class="welcome-card-subtitle">'.htmlspecialchars($subtitle).'</p>'
        .'</div>'
        .('' !== $linksHtml ? '<div class="welcome-card-links">'.$linksHtml.'</div>' : '')
        .'</div>'
        .'</section>';
}

/**
 * @param list<array{icon: string, label: string, value: string, action_html?: string}>                                                                                  $infoItems
 * @param list<array{title: string, icon: string, href: string, items: list<array{icon: string, label: string, value: string, action?: string, action_title?: string}>}> $sections
 */
function renderHomeSections(string $infoTitle, array $infoItems, array $sections, string $infoFooterHtml = '', bool $infoCardFirst = true): string
{
    $infoRows = '';
    foreach ($infoItems as $item) {
        $iconKey = (string) ($item['icon'] ?? '');
        $iconSvg = '' !== $iconKey ? lucideIcon($iconKey, 16) : '';
        $iconHtml = '' !== $iconSvg ? '<span class="info-list-icon" aria-hidden="true">'.$iconSvg.'</span>' : '';
        $actionHtml = isset($item['action_html']) ? (string) $item['action_html'] : '';
        $infoRows .= '<li class="info-list-item">'
            .$iconHtml
            .'<div class="info-list-body">'
            .'<span class="info-list-label">'.htmlspecialchars($item['label']).'</span>'
            .'<span class="info-list-value">'.$item['value'].'</span>'
            .'</div>'.$actionHtml.'</li>';
    }

    $infoCard = '';
    if ([] !== $infoItems) {
        $infoTitleSvg = lucideIcon('info', 14);
        $infoFooter = '' !== $infoFooterHtml ? '<div class="home-info-footer">'.$infoFooterHtml.'</div>' : '';
        $infoCard = '<article class="home-card">'
            .'<div class="home-card-header home-card-header--static">'
            .'<span class="home-card-title"><span class="home-card-icon" aria-hidden="true">'.$infoTitleSvg.'</span>'.htmlspecialchars($infoTitle).'</span>'
            .'</div>'
            .'<ul class="info-list">'.$infoRows.'</ul>'
            .$infoFooter
            .'</article>';
    }

    $sectionCards = '';
    foreach ($sections as $section) {
        $sectionIconKey = (string) ($section['icon'] ?? 'settings');
        $sectionIconSvg = lucideIcon($sectionIconKey, 14);
        $itemsHtml = '';
        foreach ($section['items'] as $item) {
            $itemIconKey = (string) ($item['icon'] ?? '');
            $itemIconSvg = '' !== $itemIconKey ? lucideIcon($itemIconKey, 16) : '';
            $itemIconInner = '' !== $itemIconSvg ? '<span class="info-list-icon" aria-hidden="true">'.$itemIconSvg.'</span>' : '';
            $actionHtml = '';
            if (isset($item['action']) && '' !== $item['action']) {
                $actionTitle = isset($item['action_title']) ? htmlspecialchars($item['action_title']) : '';
                $actionHtml = '<a class="info-list-action" href="'.htmlspecialchars($item['action']).'" title="'.$actionTitle.'" aria-label="'.$actionTitle.'">'.lucideIcon('settings', 16).'</a>';
            }
            $itemsHtml .= '<li class="info-list-item">'
                .$itemIconInner
                .'<div class="info-list-body">'
                .'<span class="info-list-label">'.htmlspecialchars($item['label']).'</span>'
                .'<span class="info-list-value">'.$item['value'].'</span>'
                .'</div>'.$actionHtml.'</li>';
        }
        $sectionHref = htmlspecialchars($section['href']);
        $sectionTitle = htmlspecialchars($section['title']);
        $sectionCards .= '<article class="home-card">'
            .'<a class="home-card-header" href="'.$sectionHref.'">'
            .'<span class="home-card-title"><span class="home-card-icon" aria-hidden="true">'.$sectionIconSvg.'</span>'.$sectionTitle.'</span>'
            .'<span class="home-card-cta" aria-hidden="true">'.lucideIcon('arrow-right', 16).'</span>'
            .'</a>'
            .'<ul class="info-list">'.$itemsHtml.'</ul>'
            .'</article>';
    }

    if ('' === $infoCard) {
        $cardsHtml = $sectionCards;
    } else {
        $cardsHtml = $infoCardFirst ? ($infoCard.$sectionCards) : ($sectionCards.$infoCard);
    }

    return '<div class="home-stack">'.$cardsHtml.'</div>';
}

/**
 * @param array<string, mixed> $versionMeta
 */
function buildLoginFormContent(string $error, array $versionMeta): string
{
    global $lang;
    /** @var array<string, string> $langForLogin */
    $langForLogin = (isset($lang) && is_array($lang)) ? $lang : [];

    $errorHtml = ('' !== $error) ? '<div class="error">'.htmlspecialchars((string) $error).'</div>' : '';
    $text_please_enter_password = resolveLangKey('please_enter_password', $langForLogin);
    $text_password_placeholder = resolveLangKey('password_placeholder', $langForLogin);
    $text_login = resolveLangKey('login', $langForLogin);
    $iconLogin = lucideIcon('log-in', 16);

    $versionInfoHtml = '';
    $installerVersionStr = '';
    if (isset($versionMeta['installer_version']) && is_scalar($versionMeta['installer_version'])) {
        $installerVersionStr = (string) $versionMeta['installer_version'];
    }
    $projectVersionStr = '';
    if (isset($versionMeta['project_version']) && is_scalar($versionMeta['project_version'])) {
        $projectVersionStr = (string) $versionMeta['project_version'];
    }
    $installerVersion = htmlspecialchars((string) $installerVersionStr);
    $projectVersion = htmlspecialchars((string) $projectVersionStr);
    if (isset($versionMeta['installer_version']) || isset($versionMeta['project_version'])) {
        $versionInfoHtml = '<div class="repo-info" style="margin-top:15px">'
            .'<strong>'.__('updater_version').':</strong> <code>'.$installerVersion.'</code><br>'
            .'<strong>'.__('project_version').':</strong> <code>'.$projectVersion.'</code>'
            .'</div>';
    }

    return <<<HTML
<div class="login-form">
<p>{$text_please_enter_password}</p>
{$errorHtml}
<form method="post">
    <input type="password" name="password" placeholder="{$text_password_placeholder}" autofocus>
    <button type="submit" class="btn">{$iconLogin} {$text_login}</button>
</form>
{$versionInfoHtml}
</div>
HTML;
}

/**
 * @param array<string, mixed> $versionMeta
 */
function renderLoginForm(string $error = '', array $versionMeta = []): void
{
    $content = buildLoginFormContent($error, $versionMeta);
    echo renderPage(__('title'), $content, null, null, false);
    if (PHP_SAPI !== 'cli') {
        exit;
    }
}

function renderPage(
    string $title,
    string $content,
    ?string $error = null,
    ?string $envPath = null,
    bool $showLogout = false,
    string $activeView = '',
    ?string $dashboardNotice = null,
): string {
    global $lang;
    /** @var array<string, string> $langForPage */
    $langForPage = (isset($lang) && is_array($lang)) ? $lang : [];

    $errorHtml = (null !== $error && '' !== $error) ? '<div class="error">'.htmlspecialchars((string) $error).'</div>' : '';
    $text_logout = resolveLangKey('logout', $langForPage);
    $text_close = resolveLangKey('close', $langForPage);
    $logoutButton = $showLogout ? '<form method="get" class="logout-form"><input type="hidden" name="logout" value="1"><input type="hidden" name="_t" value="'.time().'"><button type="submit" class="btn btn-secondary btn-small">'.lucideIcon('log-out', 14).' '.htmlspecialchars($text_logout).'</button></form>' : '';

    $text_language = resolveLangKey('language', $langForPage);
    global $availableLangs;
    /** @var array<string> $availableLangs */
    $sessionLangVal = (isset($_SESSION['lang']) && is_string($_SESSION['lang'])) ? (string) $_SESSION['lang'] : 'en';
    $langDropdownOptions = [];
    if (is_iterable($availableLangs)) {
        foreach ($availableLangs as $code) {
            $codeStr = (is_scalar($code)) ? (string) $code : '';
            if ('' === $codeStr) {
                continue;
            }

            $langDropdownOptions[] = ['value' => $codeStr, 'label' => strtoupper($codeStr)];
        }
    }

    $langDropdown = renderDropdown('lang', $langDropdownOptions, $sessionLangVal, true, 'dropdown-lang');

    $requestDashboardState = resolveDashboardState($_GET['view'] ?? null, $_GET['itab'] ?? null, $_POST['view'] ?? null, $_POST['itab'] ?? null);
    $langStateInputs = renderDashboardStateInputs($requestDashboardState['view'], $requestDashboardState['itab']);

    $langSwitcherHtml = <<<HTML
<div class="lang-switcher">
<form method="get" class="lang-form" id="langForm">
    <label>{$text_language}:</label>
    {$langStateInputs}
    {$langDropdown}
</form>
</div>
HTML;

    $envConfigHtml = '';
    $dbConfigHtml = '';
    $installUuidHtml = '';
    $dashboardNavHtml = '';
    $mainSection = $content;
    if (null !== $envPath) {
        $envConfig = parseEnvLocal($envPath);
        global $lang;
        /** @var array<string, string> $langForTemplate */
        $langForTemplate = (isset($lang) && is_array($lang)) ? $lang : [];

        $appEnvDropdown = renderDropdown('app_env', [
            ['value' => 'dev', 'label' => 'Dev'],
            ['value' => 'prod', 'label' => 'Prod'],
        ], (string) $envConfig['app_env'], false, 'dropdown-env');

        $databaseOptions = [];
        $activeDbValue = '';
        foreach ($envConfig['databases'] as $db) {
            $dbIdVal = (isset($db['id']) && is_scalar($db['id'])) ? (string) $db['id'] : '';
            if ('' === $dbIdVal) {
                continue;
            }

            $databaseOptions[] = ['value' => $dbIdVal, 'label' => $dbIdVal];
            if (!empty($db['active'])) {
                $activeDbValue = $dbIdVal;
            }
        }

        $hasDatabases = [] !== $databaseOptions;
        $databaseDropdown = renderDropdown('database', $databaseOptions, $activeDbValue, false, 'dropdown-db', !$hasDatabases);

        $text_mode = resolveLangKey('mode', $langForTemplate);
        $text_database = resolveLangKey('database', $langForTemplate);
        $text_save = resolveLangKey('save', $langForTemplate);
        $text_env_editor = resolveLangKey('env_editor', $langForTemplate);
        $text_env_content = resolveLangKey('env_content', $langForTemplate);
        $text_save_env_file = resolveLangKey('save_env_file', $langForTemplate);
        $text_db_manager = resolveLangKey('database_manager', $langForTemplate);
        $text_db_id = resolveLangKey('database_id', $langForTemplate);
        $text_db_url = resolveLangKey('database_url', $langForTemplate);
        $text_add_database = resolveLangKey('add_database', $langForTemplate);
        $text_remove_database = resolveLangKey('remove_database', $langForTemplate);
        $text_select_database = resolveLangKey('select_database', $langForTemplate);
        $text_active_database = resolveLangKey('active_database', $langForTemplate);
        $text_active_database_help = resolveLangKey('active_database_help', $langForTemplate);
        $text_remove_database_help = resolveLangKey('remove_database_help', $langForTemplate);
        $text_dashboard_updates = resolveLangKey('dashboard_updates', $langForTemplate);
        $text_dashboard_environment = resolveLangKey('dashboard_environment', $langForTemplate);
        $text_dashboard_databases = resolveLangKey('dashboard_databases', $langForTemplate);
        $text_app_secret = resolveLangKey('app_secret', $langForTemplate);
        $text_app_secret_help = resolveLangKey('app_secret_help', $langForTemplate);
        $text_app_secret_placeholder = resolveLangKey('app_secret_placeholder', $langForTemplate);
        $text_regenerate_app_secret = resolveLangKey('regenerate_app_secret', $langForTemplate);
        $text_dashboard_install_uuid = resolveLangKey('dashboard_install_uuid', $langForTemplate);
        $text_migrations_status = resolveLangKey('migrations_status', $langForTemplate);
        $text_run_migrations = resolveLangKey('run_migrations', $langForTemplate);
        $confirm_run_migrations = resolveLangKey('confirm_run_migrations', $langForTemplate);
        $text_install_uuid = resolveLangKey('install_uuid', $langForTemplate);
        $text_install_uuid_help = resolveLangKey('install_uuid_help', $langForTemplate);
        $text_regenerate_install_uuid = resolveLangKey('regenerate_install_uuid', $langForTemplate);
        $runMigrationsConfirmAttr = renderConfirmAttributes($text_run_migrations, $confirm_run_migrations, $text_run_migrations);

        $migrationsData = getMigrationsStatus(dirname($envPath));
        /** @var string $migrationsStatusHtml */
        $migrationsStatusHtml = (isset($migrationsData['html']) && is_scalar($migrationsData['html'])) ? (string) $migrationsData['html'] : '';
        $migrationsCount = (isset($migrationsData['count']) && is_scalar($migrationsData['count'])) ? (int) $migrationsData['count'] : 0;
        $migrationsDisabled = (0 === $migrationsCount || !empty($migrationsData['error']) || isset($migrationsData['no_migrations']) || isset($migrationsData['no_db'])) ? 'disabled' : '';

        $removeDbOptions = [];
        foreach ($envConfig['databases'] as $db) {
            $dbIdStr = (isset($db['id']) && is_scalar($db['id'])) ? (string) $db['id'] : '';
            if ('' === $dbIdStr) {
                continue;
            }

            $removeDbOptions[] = ['value' => $dbIdStr, 'label' => $dbIdStr.((!empty($db['active'])) ? ' (active)' : '')];
        }

        $canRemoveDatabases = [] !== $removeDbOptions;
        $removeDbDropdown = renderDropdown('remove_db_id', $removeDbOptions, '', false, 'dropdown-db', !$canRemoveDatabases);
        $databaseActionDisabled = $hasDatabases ? '' : 'disabled';
        $removeDatabaseActionDisabled = $canRemoveDatabases ? '' : 'disabled';

        $envRawContent = htmlspecialchars((string) $envConfig['raw_content']);
        $currentInstallUuid = htmlspecialchars((string) ($envConfig['install_uuid'] ?? ''));
        $currentAppSecret = htmlspecialchars((string) ($envConfig['app_secret'] ?? ''));
        $dashboardStateInputs = renderDashboardStateInputs($activeView, $requestDashboardState['itab']);
        $iconSave = lucideIcon('save', 14);
        $iconPlay = lucideIcon('play', 14);
        $iconPlus = lucideIcon('plus', 14);
        $iconTrash = lucideIcon('trash-2', 14);
        $iconRefresh = lucideIcon('refresh-cw', 14);

        $envConfigHtml = <<<HTML
<div class="env-config">
<form method="post" class="env-form env-form--stack">
    {$dashboardStateInputs}

    <div class="env-row env-row--stack env-row--wide">
        <label>{$text_mode}:</label>
        {$appEnvDropdown}
    </div>

    <div class="env-row env-row--stack env-row--wide">
        <label for="app_secret_input">{$text_app_secret}:</label>
        <div class="input-group">
            <input type="text" id="app_secret_input" name="app_secret" value="{$currentAppSecret}" class="env-input env-input--secret" placeholder="{$text_app_secret_placeholder}">
            <button type="submit" name="regenerate_app_secret" value="1" class="input-group-append" title="{$text_regenerate_app_secret}" aria-label="{$text_regenerate_app_secret}">{$iconRefresh}</button>
        </div>
        <p class="env-help">{$text_app_secret_help}</p>
    </div>

    <div class="env-row env-row--stack env-row--wide">
        <label for="env_content_textarea">{$text_env_editor}:</label>
        <textarea id="env_content_textarea" name="env_content" class="env-textarea">{$envRawContent}</textarea>
    </div>

    <div class="env-actions">
        <button type="submit" name="save_env_content" class="btn btn-secondary">{$iconSave} {$text_save}</button>
    </div>
</form>
</div>
HTML;

        $dbConfigHtml = <<<HTML
<div class="env-config">
    <div class="env-section-header">
        <div>
            <h3 class="env-section-title">{$text_migrations_status}</h3>
            <div>{$migrationsStatusHtml}</div>
        </div>
        <form method="post" {$runMigrationsConfirmAttr}>
            <button type="submit" name="run_migrations" value="1" class="btn btn-secondary" {$migrationsDisabled}>{$iconPlay} {$text_run_migrations}</button>
        </form>
    </div>

    <hr class="env-divider">

    <form method="post" class="env-form env-form--stack">
        {$dashboardStateInputs}
        <div class="env-row env-row--stack env-row--wide">
            <label>{$text_active_database}:</label>
            {$databaseDropdown}
            <p class="env-help">{$text_active_database_help}</p>
        </div>
        <div class="env-actions">
            <button type="submit" name="save_env" value="1" class="btn btn-secondary" {$databaseActionDisabled}>{$iconSave} {$text_save}</button>
        </div>
    </form>

    <hr class="env-divider">

    <h3 class="env-section-title">{$text_db_manager}</h3>
    <form method="post" class="env-form env-form--stack">
        {$dashboardStateInputs}
        <div class="env-row env-row--stack env-row--wide">
            <label for="db_id_input">{$text_db_id}:</label>
            <input type="text" id="db_id_input" name="db_id" class="env-input env-input--wide" required>
        </div>
        <div class="env-row env-row--stack env-row--wide">
            <label for="db_url_input">{$text_db_url}:</label>
            <input type="text" id="db_url_input" name="db_url" class="env-input env-input--wide" required>
        </div>
        <div class="env-actions">
            <button type="submit" name="add_database" value="1" class="btn btn-secondary">{$iconPlus} {$text_add_database}</button>
        </div>
    </form>

    <hr class="env-divider">

    <form method="post" class="env-form env-form--stack">
        {$dashboardStateInputs}
        <div class="env-row env-row--stack env-row--wide">
            <label>{$text_remove_database}:</label>
            {$removeDbDropdown}
            <p class="env-help">{$text_remove_database_help}</p>
        </div>
        <div class="env-actions">
            <button type="submit" name="remove_database" value="1" class="btn" {$removeDatabaseActionDisabled}>{$iconTrash} {$text_remove_database}</button>
        </div>
    </form>
</div>
HTML;

        $installUuidHtml = <<<HTML
<div class="env-config">
    <form method="post" class="env-form env-form--stack">
        {$dashboardStateInputs}
        <div class="env-row env-row--stack env-row--wide">
            <label for="install_uuid_input">{$text_install_uuid}:</label>
            <input type="text" id="install_uuid_input" name="install_uuid" value="{$currentInstallUuid}" class="env-input env-input--uuid">
            <p class="env-help">{$text_install_uuid_help}</p>
        </div>
        <div class="env-actions">
            <button type="submit" name="save_install_uuid" value="1" class="btn btn-secondary">{$iconSave} {$text_save}</button>
            <button type="submit" name="regenerate_install_uuid" value="1" class="btn">{$iconRefresh} {$text_regenerate_install_uuid}</button>
        </div>
    </form>
</div>
HTML;

        $iconNavHome = lucideIcon('home', 16);
        $iconNavUpdates = lucideIcon('download', 16);
        $iconNavEnv = lucideIcon('settings', 16);
        $iconNavDb = lucideIcon('database', 16);
        $iconNavUuid = lucideIcon('fingerprint', 16);
        $iconNavSystem = lucideIcon('info', 16);
        $iconNavInstaller = lucideIcon('wrench', 16);
        $text_dashboard_home = resolveLangKey('dashboard_home', $langForTemplate);
        $text_dashboard_system = resolveLangKey('dashboard_system', $langForTemplate);
        $text_dashboard_installer = resolveLangKey('dashboard_installer', $langForTemplate);
        $dashboardNavHtml = <<<HTML
<div class="dashboard-nav">
    <a class="dashboard-btn btn btn-secondary btn-small" id="btn-home" href="?">{$iconNavHome} {$text_dashboard_home}</a>
    <a class="dashboard-btn btn btn-secondary btn-small" id="btn-updates" href="?view=updates">{$iconNavUpdates} {$text_dashboard_updates}</a>
    <a class="dashboard-btn btn btn-secondary btn-small" id="btn-environment" href="?view=environment">{$iconNavEnv} {$text_dashboard_environment}</a>
    <a class="dashboard-btn btn btn-secondary btn-small" id="btn-databases" href="?view=databases">{$iconNavDb} {$text_dashboard_databases}</a>
    <a class="dashboard-btn btn btn-secondary btn-small" id="btn-install-uuid" href="?view=install-uuid">{$iconNavUuid} {$text_dashboard_install_uuid}</a>
    <a class="dashboard-btn btn btn-secondary btn-small" id="btn-system" href="?view=system">{$iconNavSystem} {$text_dashboard_system}</a>
    <a class="dashboard-btn btn btn-secondary btn-small" id="btn-installer" href="?view=installer">{$iconNavInstaller} {$text_dashboard_installer}</a>
</div>
HTML;

        if ('' !== $activeView && 'home' !== $activeView) {
            $sectionContent = match ($activeView) {
                'environment' => $envConfigHtml,
                'databases' => $dbConfigHtml,
                'install-uuid' => $installUuidHtml,
                default => '',
            };
            if ('' !== $sectionContent) {
                $mainSection = $sectionContent;
            }
        }
    }

    $dashboardNoticeHtml = (null !== $dashboardNotice && '' !== $dashboardNotice) ? $dashboardNotice : '';

    global $lang;
    /** @var array<string, string> $langForTitle */
    $langForTitle = (isset($lang) && is_array($lang)) ? $lang : [];
    $brandTitle = resolveLangKey('brand_title', $langForTitle);
    $appTitle = resolveLangKey('title', $langForTitle);
    $brandTitleEscaped = '' !== $brandTitle ? htmlspecialchars($brandTitle) : htmlspecialchars($appTitle);
    $sessionLangForTitle = 'en';
    if (isset($_SESSION['lang']) && is_string($_SESSION['lang'])) {
        $sessionLangForTitle = $_SESSION['lang'];
    }
    $langCode = $sessionLangForTitle;

    $pageTitleEscaped = htmlspecialchars($title);
    $iconExternalLink = lucideIcon('external-link', 14);
    $confirmationModal = renderConfirmationModal('modal-confirm', $text_close);
    $dropdownScript = <<<'JS'
<script>
(function(){
  document.querySelectorAll('.dropdown').forEach(function(dd){
    var toggle = dd.querySelector('.dropdown-toggle');
    var menu = dd.querySelector('.dropdown-menu');
    var hidden = dd.querySelector('input[type="hidden"]');
    if(!toggle || !menu || !hidden){ return; }
    function close(){ dd.classList.remove('is-open'); toggle.setAttribute('aria-expanded','false'); }
    function open(){ dd.classList.add('is-open'); toggle.setAttribute('aria-expanded','true'); }
    toggle.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); dd.classList.contains('is-open') ? close() : open(); });
    menu.querySelectorAll('.dropdown-option').forEach(function(opt){
      opt.addEventListener('click', function(e){
        e.preventDefault(); e.stopPropagation();
        var value = opt.getAttribute('data-value');
        var label = opt.textContent.trim();
        hidden.value = value;
        var labelEl = toggle.querySelector('.dropdown-label');
        if(labelEl){ labelEl.textContent = label; }
        menu.querySelectorAll('.dropdown-option').forEach(function(o){ o.classList.remove('is-selected'); o.setAttribute('aria-selected','false'); });
        opt.classList.add('is-selected'); opt.setAttribute('aria-selected','true');
        close();
        if(dd.getAttribute('data-autosubmit')==='1'){
          var form = dd.closest('form');
          if(form){ form.submit(); }
        }
      });
    });
  });
  document.addEventListener('click', function(e){
    document.querySelectorAll('.dropdown.is-open').forEach(function(dd){
      if(!dd.contains(e.target)){ dd.classList.remove('is-open'); var t=dd.querySelector('.dropdown-toggle'); if(t){t.setAttribute('aria-expanded','false');} }
    });
  });
})();
</script>
JS;

    $modalScript = <<<'JS'
<script>
(function(){
  function openModal(id){
    var m = document.getElementById(id);
    if(!m){ return; }
    m.classList.add('is-open');
    m.setAttribute('aria-hidden','false');
    document.body.classList.add('modal-open');
  }
  function closeModal(id){
    var m = document.getElementById(id);
    if(!m){ return; }
    m.classList.remove('is-open');
    m.setAttribute('aria-hidden','true');
    document.body.classList.remove('modal-open');
  }
  document.querySelectorAll('[data-modal-open]').forEach(function(el){
    el.addEventListener('click', function(e){ e.preventDefault(); openModal(el.getAttribute('data-modal-open')); });
  });
  document.querySelectorAll('[data-modal-close]').forEach(function(el){
    el.addEventListener('click', function(e){ e.preventDefault(); closeModal(el.getAttribute('data-modal-close')); });
  });
  document.querySelectorAll('.modal').forEach(function(m){
    m.addEventListener('click', function(e){ if(e.target === m){ closeModal(m.id); } });
  });
  document.addEventListener('keydown', function(e){
    if('Escape' === e.key){
      document.querySelectorAll('.modal.is-open').forEach(function(m){ closeModal(m.id); });
      document.querySelectorAll('.dropdown.is-open').forEach(function(dd){ dd.classList.remove('is-open'); });
    }
  });

  document.querySelectorAll('form[data-confirm-title]').forEach(function(form){
    form.addEventListener('submit', function(e){
      if(form.dataset.confirmed === '1'){ return; }
      e.preventDefault();
      var title = form.getAttribute('data-confirm-title') || '';
      var msg = form.getAttribute('data-confirm-message') || '';
      var submitLabel = form.getAttribute('data-confirm-submit-label') || '';
      var modal = document.getElementById('modal-confirm');
      if(!modal){ return; }
      modal.querySelector('[data-confirm-title]').textContent = title;
      modal.querySelector('[data-confirm-message]').textContent = msg;
      var submitBtn = modal.querySelector('[data-confirm-submit]');
      submitBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="14" height="14"><path d="M20 6 9 17l-5-5"/></svg> '+submitLabel;
      var newSubmit = submitBtn.cloneNode(true);
      submitBtn.parentNode.replaceChild(newSubmit, submitBtn);
      newSubmit.addEventListener('click', function(){
        form.dataset.confirmed = '1';
        closeModal('modal-confirm');
        form.submit();
      });
      openModal('modal-confirm');
    });
  });
})();
</script>
JS;

    return <<<HTML
<!DOCTYPE html>
<html lang="{$langCode}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$pageTitleEscaped} · {$brandTitleEscaped}</title>
<style>
    :root {
        color-scheme: light dark;
        --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        --font-mono: 'JetBrains Mono', 'SF Mono', 'Cascadia Code', Consolas, 'Liberation Mono', monospace;
        --bg: #eef1f6;
        --bg-gradient: radial-gradient(1200px 600px at 50% -10%, #e7ecf6 0%, #eef1f6 45%, #e9edf3 100%);
        --surface: #ffffff;
        --surface-muted: #f5f7fb;
        --surface-inset: #eef1f7;
        --border: #e3e8f0;
        --border-strong: #d2d9e6;
        --text: #1f2733;
        --text-muted: #5b6675;
        --text-soft: #8a94a6;
        --brand: #5b6cff;
        --brand-strong: #4250e6;
        --brand-soft: rgba(91, 108, 255, 0.12);
        --accent: #1f9d6b;
        --accent-strong: #178257;
        --danger: #d8392b;
        --code-bg: #1f2733;
        --code-text: #e8ecf4;
        --shadow-sm: 0 1px 2px rgba(16, 24, 40, 0.06);
        --shadow-md: 0 10px 30px -12px rgba(16, 24, 40, 0.25);
        --shadow-lg: 0 24px 60px -20px rgba(16, 24, 40, 0.35);
        --radius-sm: 8px;
        --radius: 12px;
        --radius-lg: 18px;
        --ring: 0 0 0 3px var(--brand-soft);
    }
    @media (prefers-color-scheme: dark) {
        :root {
            --bg: #0c0f16;
            --bg-gradient: radial-gradient(1200px 600px at 50% -10%, #14304a 0%, #0e1320 45%, #0c0f16 100%);
            --surface: #131825;
            --surface-muted: #171d2c;
            --surface-inset: #1b2233;
            --border: #232c3f;
            --border-strong: #2c374e;
            --text: #e8ecf4;
            --text-muted: #a4afc2;
            --text-soft: #7c879b;
            --brand: #7c8bff;
            --brand-strong: #6677ff;
            --brand-soft: rgba(124, 139, 255, 0.16);
            --accent: #34d399;
            --accent-strong: #10b981;
            --danger: #f87171;
            --code-bg: #0b0f18;
            --code-text: #d7def0;
            --shadow-md: 0 10px 30px -12px rgba(0, 0, 0, 0.6);
            --shadow-lg: 0 24px 60px -20px rgba(0, 0, 0, 0.7);
        }
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: var(--font-sans);
        background: var(--bg);
        background-image: var(--bg-gradient);
        background-attachment: fixed;
        color: var(--text);
        padding: 32px 18px 64px;
        line-height: 1.55;
        -webkit-font-smoothing: antialiased;
        text-rendering: optimizeLegibility;
    }
    h3 { font-size: 1.02rem; font-weight: 650; letter-spacing: -0.01em; }
    a { color: var(--brand); }
    code, pre { font-family: var(--font-mono); }
    .container {
        max-width: 1040px;
        margin: 0 auto;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-lg);
        padding: 34px 36px;
    }
    header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 18px;
        margin-bottom: 26px;
        padding-bottom: 22px;
        border-bottom: 1px solid var(--border);
    }
    .brand { display: flex; align-items: center; gap: 14px; }
    .brand-mark {
        width: 54px; height: 54px;
        display: grid; place-items: center;
        padding: 6px;
        border-radius: 16px;
        background: var(--surface-muted);
        border: 1px solid var(--border);
        color: var(--brand);
    }
    .brand-mark svg { width: 100%; height: 100%; }
    .header-left h1 { font-size: 1.4rem; font-weight: 700; letter-spacing: -0.01em; color: var(--text); }
    .header-left h2 { font-size: 0.95rem; font-weight: 500; color: var(--text-muted); margin-top: 2px; }
    .header-right { display: flex; flex-direction: column; align-items: flex-end; gap: 10px; }
    .header-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    @media (max-width: 600px) {
        .container { padding: 24px 20px; border-radius: var(--radius); }
        header { flex-direction: column; align-items: stretch; }
        .header-right { align-items: flex-start; }
    }
    .home-stack { display: flex; flex-direction: column; gap: 16px; }
    .home-stack > * + * { margin-top: 0; }
    .welcome-card {
        position: relative;
        overflow: hidden;
        border: 1px solid color-mix(in srgb, var(--brand) 25%, var(--border));
        border-radius: var(--radius);
        background: linear-gradient(135deg, color-mix(in srgb, var(--brand) 12%, var(--surface)) 0%, var(--surface) 60%);
        box-shadow: var(--shadow-sm);
        margin-bottom: 0;
    }
    .welcome-card-glow {
        position: absolute;
        inset: -40% -10% auto auto;
        width: 60%;
        height: 220%;
        background: radial-gradient(closest-side, color-mix(in srgb, var(--brand) 22%, transparent) 0%, transparent 70%);
        pointer-events: none;
        opacity: .85;
    }
    .welcome-card-body {
        position: relative;
        display: flex;
        align-items: center;
        gap: 18px;
        padding: 22px 24px;
        flex-wrap: wrap;
    }
    .welcome-card-icon {
        flex: none;
        display: grid;
        place-items: center;
        width: 48px;
        height: 48px;
        border-radius: 14px;
        background: var(--brand);
        color: #fff;
        box-shadow: 0 6px 18px -8px color-mix(in srgb, var(--brand) 70%, transparent);
    }
    .welcome-card-icon svg { width: 22px; height: 22px; display: block; }
    .welcome-card-content { min-width: 0; flex: 1 1 280px; }
    .welcome-card-title {
        font-size: 1.18rem;
        font-weight: 700;
        letter-spacing: -0.01em;
        color: var(--text);
        margin: 0 0 4px;
    }
    .welcome-card-subtitle {
        font-size: 0.93rem;
        color: var(--text-muted);
        line-height: 1.55;
        margin: 0;
    }
    .welcome-card-links {
        flex: 0 1 auto;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .welcome-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 13px;
        border-radius: 999px;
        background: var(--surface);
        border: 1px solid var(--border);
        color: var(--brand-strong);
        font-size: 0.85rem;
        font-weight: 600;
        text-decoration: none;
        box-shadow: var(--shadow-sm);
        transition: border-color .15s ease, color .15s ease, transform .06s ease, box-shadow .15s ease;
    }
    .welcome-link:hover { border-color: var(--brand); color: var(--brand); box-shadow: var(--shadow-md); }
    .welcome-link:active { transform: translateY(1px); }
    .welcome-link svg { width: 14px; height: 14px; flex-shrink: 0; }
    .home-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; display: flex; flex-direction: column; }
    .home-card-header {
        display: flex; align-items: center; justify-content: space-between; gap: 10px;
        padding: 12px 16px;
        border-bottom: 1px solid var(--border);
        background: var(--surface-muted);
        text-decoration: none;
        color: var(--text);
        transition: background 0.15s ease, color 0.15s ease;
    }
    .home-card-header--static { cursor: default; }
    .home-card-header--static:hover { background: var(--surface-muted); color: var(--text); }
    .home-card-header:hover { background: color-mix(in srgb, var(--brand) 8%, var(--surface-muted)); color: var(--brand-strong); }
    .home-card-header:focus-visible { outline: none; box-shadow: var(--ring); }
    .home-card-title { display: inline-flex; align-items: center; gap: 8px; font-size: 0.92rem; font-weight: 650; }
    .home-card-icon { display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 6px; background: var(--brand-soft); color: var(--brand-strong); }
    .home-card-icon svg { width: 14px; height: 14px; display: block; }
    .home-card-cta { color: var(--text-soft); display: inline-grid; place-items: center; }
    .home-card-cta svg { width: 16px; height: 16px; display: block; }
    .home-card-header:hover .home-card-cta { color: var(--brand-strong); }
    .home-card .info-list { border: none; border-radius: 0; }
    .home-card .info-list-item { padding: 12px 16px; }
    .info-list { list-style: none; }
    .info-list-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 16px;
        border-bottom: 1px solid var(--border);
    }
    .info-list-item:last-child { border-bottom: none; }
    .info-list-icon {
        flex: none;
        display: inline-grid;
        place-items: center;
        width: 28px; height: 28px;
        border-radius: 8px;
        background: var(--brand-soft);
        color: var(--brand-strong);
    }
    .info-list-icon svg { width: 16px; height: 16px; display: block; }
    .info-list-body { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
    .info-list-label { font-size: 0.85rem; font-weight: 600; color: var(--text-muted); }
    .info-list-value { font-size: 0.92rem; color: var(--text); word-break: break-word; }
    .info-list-meta { color: var(--text-soft); font-size: 0.85rem; }
    .info-list-action {
        flex: none;
        display: inline-grid;
        place-items: center;
        width: 32px; height: 32px;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--text-muted);
        text-decoration: none;
        transition: color .15s ease, border-color .15s ease, background .15s ease, box-shadow .15s ease;
    }
    .info-list-action:hover { color: var(--brand-strong); border-color: var(--brand); background: var(--surface); box-shadow: var(--shadow-sm); }
    .info-list-action svg { width: 16px; height: 16px; display: block; }
    .home-info-footer { padding: 12px 16px; border-top: 1px solid var(--border); background: var(--surface-muted); }
    .status-badge {
        font-size: 0.68rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 2px 9px;
        border-radius: 999px;
        background: color-mix(in srgb, var(--accent) 16%, var(--surface));
        color: var(--accent-strong);
        border: 1px solid color-mix(in srgb, var(--accent) 30%, transparent);
    }
    .status-chips { display: flex; flex-wrap: wrap; gap: 6px; }
    .status-chip {
        font-size: 0.78rem;
        padding: 3px 10px;
        border-radius: 999px;
        background: var(--surface-muted);
        border: 1px solid var(--border);
        color: var(--text-muted);
        font-family: var(--font-mono);
    }
    .error, .success, .warning {
        padding: 14px 16px 14px 18px;
        border-radius: var(--radius);
        margin-bottom: 20px;
        border: 1px solid transparent;
        border-left-width: 4px;
        font-size: 0.93rem;
    }
    .error { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: var(--danger); border-color: color-mix(in srgb, var(--danger) 35%, transparent); }
    .success { background: color-mix(in srgb, var(--accent) 12%, var(--surface)); color: var(--accent-strong); border-color: color-mix(in srgb, var(--accent) 35%, transparent); }
    .warning { background: color-mix(in srgb, #e0a72e 14%, var(--surface)); color: #9a6b00; border-color: color-mix(in srgb, #e0a72e 40%, transparent); }
    .success code, .warning code, .error code { background: rgba(127,127,127,0.18); color: inherit; }
    .branch-list, .tag-list { list-style: none; border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
    .branch-list li, .tag-list li {
        padding: 12px 16px;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        transition: background 0.15s ease;
    }
    .branch-list li:last-child, .tag-list li:last-child { border-bottom: none; }
    .branch-list li:hover, .tag-list li:hover { background: var(--surface-muted); }
    .branch-name, .tag-name { font-family: var(--font-mono); color: var(--brand); font-weight: 600; font-size: 0.92rem; }
    .commit-sha { font-family: var(--font-mono); font-size: 0.82em; color: var(--text-soft); margin-left: 10px; }
    .btn {
        background: var(--accent);
        color: #fff;
        border: none;
        padding: 8px 16px;
        border-radius: 9px;
        cursor: pointer;
        font-size: 0.88rem;
        font-weight: 600;
        font-family: inherit;
        line-height: 1.2;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: transform 0.06s ease, background 0.15s ease, box-shadow 0.15s ease;
        box-shadow: var(--shadow-sm);
    }
    .btn:hover { background: var(--accent-strong); box-shadow: var(--shadow-md); }
    .btn:active { transform: translateY(1px); }
    .btn:focus-visible { outline: none; box-shadow: var(--ring); }
    .btn:disabled { opacity: 0.5; cursor: not-allowed; box-shadow: none; }
    .btn svg { width: 15px; height: 15px; flex-shrink: 0; margin-right: 4px; }
    .dashboard-btn svg { width: 16px; height: 16px; }
    .tab svg { width: 15px; height: 15px; flex-shrink: 0; margin-right: 4px; }
    .modal-close svg { width: 16px; height: 16px; margin-right: 0; }
    .footer-link svg { width: 14px; height: 14px; flex-shrink: 0; margin-right: 0; }
    .btn-secondary { background: var(--brand); }
    .btn-secondary:hover { background: var(--brand-strong); }
    .btn-small { padding: 7px 13px; font-size: 0.82rem; }
    .dashboard-nav { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 22px; padding: 5px; background: var(--surface-inset); border: 1px solid var(--border); border-radius: 13px; }
    .dashboard-btn { background: transparent; color: var(--text-muted); box-shadow: none; border-radius: 9px; }
    .dashboard-btn:hover { background: color-mix(in srgb, var(--brand) 10%, transparent); color: var(--text); }
    .dashboard-btn.active { background: var(--surface); color: var(--text); box-shadow: var(--shadow-sm); }
    .tabs { display: flex; gap: 6px; margin-bottom: 18px; padding: 5px; background: var(--surface-inset); border: 1px solid var(--border); border-radius: 13px; width: fit-content; }
    .tab { background: transparent; border: none; padding: 8px 16px; cursor: pointer; border-radius: 9px; font-family: inherit; font-size: 0.88rem; font-weight: 600; color: var(--text-muted); text-decoration: none; display: inline-flex; align-items: center; }
    .tab:hover { color: var(--text); }
    .tab.active { background: var(--surface); color: var(--text); box-shadow: var(--shadow-sm); }
    .file-list { list-style: none; padding: 14px 16px; max-height: 320px; overflow-y: auto; background: var(--surface-muted); border: 1px solid var(--border); border-radius: var(--radius); }
    .file-list li { padding: 4px 0; font-family: var(--font-mono); font-size: 0.84rem; color: var(--text-muted); }
    .file-list--in-card { border: none; border-radius: 0; background: transparent; }
    .back-link { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 20px; color: var(--brand); text-decoration: none; font-weight: 600; font-size: 0.9rem; }
    .back-link::before { content: ''; display: inline-block; width: 16px; height: 16px; background: currentColor; -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m12 19-7-7 7-7'/%3E%3Cpath d='M19 12H5'/%3E%3C/svg%3E") no-repeat center/contain; mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m12 19-7-7 7-7'/%3E%3Cpath d='M19 12H5'/%3E%3C/svg%3E") no-repeat center/contain; }
    .back-link:hover { text-decoration: underline; }
    .logout-form { display: inline-block; }
    .login-form { max-width: 340px; margin: 36px auto; }
    .login-form p { color: var(--text-muted); margin-bottom: 14px; }
    .login-form input[type="password"] { width: 100%; padding: 12px 14px; border: 1px solid var(--border-strong); border-radius: 10px; margin-bottom: 12px; font-size: 1em; font-family: inherit; background: var(--surface-muted); color: var(--text); }
    .login-form input[type="password"]:focus { outline: none; border-color: var(--brand); box-shadow: var(--ring); }
    .login-form .btn { width: 100%; padding: 12px; }
    .env-config { background: var(--surface-muted); border: 1px solid var(--border); padding: 20px; border-radius: var(--radius); margin-bottom: 22px; }
    .env-form { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
    .env-form--stack { align-items: stretch; }
    .env-row { display: flex; align-items: center; gap: 8px; }
    .env-row--stack { flex-direction: column; align-items: stretch; gap: 6px; width: min(100%, 420px); }
    .env-row--wide { width: 100%; }
    .env-row label { font-weight: 600; color: var(--text-muted); font-size: 0.88rem; }
    .env-help { color: var(--text-muted); font-size: 0.82rem; margin-top: 4px; line-height: 1.45; }
    .env-select, .env-input {
        padding: 8px 12px;
        border: 1px solid var(--border-strong);
        border-radius: 9px;
        font-size: 0.88rem;
        font-family: inherit;
        background: var(--surface);
        color: var(--text);
        min-width: 110px;
    }
    .env-input--wide { width: 100%; }
    .env-input--uuid { flex: 1 1 auto; width: 100%; min-width: 0; font-family: var(--font-mono); }
    .env-input--secret { flex: 1 1 auto; width: 100%; min-width: 0; font-family: var(--font-mono); }
    .input-group { display: flex; align-items: stretch; }
    .input-group > .env-input { flex: 1 1 auto; min-width: 0; border-top-right-radius: 0; border-bottom-right-radius: 0; }
    .input-group > .dropdown { flex: 1 1 auto; min-width: 0; }
    .input-group > .dropdown .dropdown-toggle { border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none; }
    .input-group > .input-group-append {
        display: inline-flex; align-items: center; justify-content: center;
        flex: 0 0 auto;
        padding: 0 12px;
        background: var(--brand);
        color: #fff;
        border: 1px solid var(--brand);
        border-left: none;
        border-top-left-radius: 0; border-bottom-left-radius: 0;
        border-top-right-radius: 9px; border-bottom-right-radius: 9px;
        cursor: pointer;
        font-family: inherit;
        box-shadow: var(--shadow-sm);
        transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.06s ease;
    }
    .input-group > .input-group-append:hover { background: var(--brand-strong); border-color: var(--brand-strong); box-shadow: var(--shadow-md); }
    .input-group > .input-group-append:active { transform: translateY(1px); }
    .input-group > .input-group-append:focus-visible { outline: none; box-shadow: var(--ring); }
    .input-group > .input-group-append svg { width: 15px; height: 15px; flex-shrink: 0; }
    .env-section-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
    .env-section-title { font-size: 1.02rem; font-weight: 650; letter-spacing: -0.01em; margin-bottom: 6px; }
    .env-divider { border: none; border-top: 1px solid var(--border); margin: 20px 0; }
    .env-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .env-select:focus, .env-input:focus { outline: none; border-color: var(--brand); box-shadow: var(--ring); }
    .env-textarea { width: 100%; min-height: 200px; padding: 12px 14px; border: 1px solid var(--border-strong); border-radius: var(--radius); font-family: var(--font-mono); font-size: 0.86rem; background: var(--surface); color: var(--text); resize: vertical; }
    .env-textarea:focus { outline: none; border-color: var(--brand); box-shadow: var(--ring); }
    pre { background: var(--surface-muted); border: 1px solid var(--border); padding: 15px; border-radius: var(--radius); font-size: 0.86rem; white-space: pre-wrap; color: var(--text-muted); }
    hr { border: none; border-top: 1px solid var(--border); }
    .lang-switcher { margin: 0; }
    .lang-form { display: flex; align-items: center; gap: 6px; }
    .lang-form label { font-size: 0.82rem; color: var(--text-muted); }
    footer { margin-top: 28px; text-align: center; }
    .footer-link { color: var(--text-soft); text-decoration: none; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 5px; }
    .footer-link:hover { color: var(--brand); text-decoration: underline; }
    .dropdown { position: relative; display: inline-block; min-width: 130px; text-align: left; }
    .dropdown-toggle {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding: 8px 12px;
        border: 1px solid var(--border-strong);
        border-radius: 9px;
        background: var(--surface);
        color: var(--text);
        font-size: 0.88rem;
        font-family: inherit;
        cursor: pointer;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .dropdown-toggle:disabled { opacity: 0.55; cursor: not-allowed; }
    .dropdown-toggle:hover { border-color: var(--brand); }
    .dropdown.is-open .dropdown-toggle, .dropdown-toggle:focus-visible { outline: none; border-color: var(--brand); box-shadow: var(--ring); }
    .dropdown-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dropdown-chevron { width: 16px; height: 16px; flex-shrink: 0; color: var(--text-soft); transition: transform 0.2s ease; }
    .dropdown.is-open .dropdown-chevron { transform: rotate(180deg); }
    .dropdown-menu {
        position: absolute;
        z-index: 50;
        top: calc(100% + 6px);
        left: 0;
        min-width: 100%;
        margin: 0;
        padding: 6px;
        list-style: none;
        background: var(--surface);
        border: 1px solid var(--border-strong);
        border-radius: 11px;
        box-shadow: var(--shadow-lg);
        max-height: 280px;
        overflow-y: auto;
        opacity: 0;
        transform: translateY(-6px) scale(0.98);
        transform-origin: top;
        pointer-events: none;
        transition: opacity 0.15s ease, transform 0.15s ease;
    }
    .dropdown.is-open .dropdown-menu { opacity: 1; transform: translateY(0) scale(1); pointer-events: auto; }
    .dropdown-option {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 8px 10px;
        border-radius: 7px;
        cursor: pointer;
        font-size: 0.88rem;
        color: var(--text);
        white-space: nowrap;
    }
    .dropdown-option:hover { background: var(--surface-muted); }
    .dropdown-option.is-selected { color: var(--brand-strong); font-weight: 600; }
    .dropdown-option.is-selected::after { content: ''; display: inline-block; width: 14px; height: 14px; flex-shrink: 0; background: var(--brand); -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M20 6 9 17l-5-5'/%3E%3C/svg%3E") no-repeat center/contain; mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M20 6 9 17l-5-5'/%3E%3C/svg%3E") no-repeat center/contain; }
    .modal {
        position: fixed;
        inset: 0;
        z-index: 100;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .modal.is-open { display: flex; }
    .modal-backdrop {
        position: absolute;
        inset: 0;
        background: color-mix(in srgb, #0b1220 55%, transparent);
        backdrop-filter: blur(2px);
    }
    .modal-dialog {
        position: relative;
        width: 100%;
        max-width: 460px;
        max-height: 80vh;
        display: flex;
        flex-direction: column;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-lg);
        animation: modalIn .16s ease;
    }
    @keyframes modalIn { from { opacity: 0; transform: translateY(8px) scale(0.98); } to { opacity: 1; transform: none; } }
    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 16px 18px;
        border-bottom: 1px solid var(--border);
    }
    .modal-title { font-size: 1.02rem; font-weight: 650; color: var(--text); }
    .modal-close {
        flex: none;
        width: 30px;
        height: 30px;
        border: none;
        border-radius: 8px;
        background: transparent;
        color: var(--text-muted);
        display: grid;
        place-items: center;
        cursor: pointer;
        transition: background .15s ease, color .15s ease;
    }
    .modal-close:hover { background: var(--surface-muted); color: var(--text); }
    .modal-body { padding: 14px 18px 18px; overflow-y: auto; }
    .confirm-message { color: var(--text); line-height: 1.55; margin: 0; }
    .modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 18px;
    }
    .modal-list { list-style: none; display: flex; flex-direction: column; gap: 8px; }
    .modal-list li { display: flex; }
    .modal-list code {
        flex: 1;
        background: var(--code-bg);
        color: var(--code-text);
        padding: 7px 11px;
        border-radius: 8px;
        font-size: 0.86rem;
        word-break: break-all;
    }
    body.modal-open { overflow: hidden; }
    .updates-section-card .tag-list,
    .updates-section-card .branch-list {
        border: none;
        border-radius: 0;
        background: transparent;
    }
    .updates-section-card .tag-list li,
    .updates-section-card .branch-list li { border-bottom: 1px solid var(--border); }
    .updates-section-card .tag-list li:last-child,
    .updates-section-card .branch-list li:last-child { border-bottom: none; }
    .installer-tabs { margin-bottom: 0; align-self: flex-start; }
    .ref-item-info { display: inline-flex; align-items: center; gap: 4px; min-width: 0; }
    .install-form { display: inline-flex; align-items: center; gap: 10px; }
    .status-count {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 4px 6px 4px 4px;
        border: 1px solid var(--border);
        border-radius: 999px;
        background: var(--surface-muted);
        color: var(--text);
        font: inherit;
        font-size: 0.82rem;
        cursor: pointer;
        transition: border-color .15s ease, background .15s ease;
    }
    .status-count:hover { border-color: var(--brand); background: color-mix(in srgb, var(--brand) 10%, var(--surface)); }
    .status-count:focus-visible { outline: none; box-shadow: var(--ring); }
    .status-count-num {
        display: grid;
        place-items: center;
        min-width: 22px;
        height: 22px;
        padding: 0 6px;
        border-radius: 999px;
        background: var(--brand);
        color: #fff;
        font-size: 0.78rem;
        font-weight: 700;
    }
    .status-count-text { color: var(--text-muted); padding-right: 4px; }
    .updates-header-card { padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
    .updates-meta { display: flex; flex-direction: column; gap: 4px; }
    .updates-meta-row { display: flex; align-items: center; gap: 6px; font-size: 0.88rem; }
    .updates-meta-label { color: var(--text-muted); font-weight: 600; }
    .updates-meta-value code { background: var(--surface); border: 1px solid var(--border); padding: 1px 7px; border-radius: 6px; font-size: 0.85rem; }
    .updates-empty { color: var(--text-soft); font-style: italic; padding: 10px 16px; }
    .repo-info {
        background: var(--surface-muted);
        border: 1px solid var(--border);
        padding: 14px 16px;
        border-radius: var(--radius);
        margin-bottom: 18px;
        font-size: 0.92rem;
    }
    .repo-info code { background: var(--surface); border: 1px solid var(--border); padding: 1px 7px; border-radius: 6px; font-size: 0.85rem; }
</style>
</head>
<body>
<div class="container">
    <header>
        <div class="brand">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .5C5.65.5.5 5.65.5 12c0 5.08 3.29 9.39 7.86 10.91.58.11.79-.25.79-.56 0-.28-.01-1.02-.02-2-3.2.7-3.88-1.54-3.88-1.54-.52-1.33-1.28-1.69-1.28-1.69-1.05-.72.08-.7.08-.7 1.16.08 1.77 1.19 1.77 1.19 1.03 1.77 2.71 1.26 3.37.96.1-.75.4-1.26.73-1.55-2.55-.29-5.24-1.28-5.24-5.69 0-1.26.45-2.29 1.18-3.1-.12-.29-.51-1.46.11-3.04 0 0 .97-.31 3.18 1.18a11.04 11.04 0 0 1 5.79 0c2.2-1.49 3.17-1.18 3.17-1.18.62 1.58.23 2.75.11 3.04.74.81 1.18 1.84 1.18 3.1 0 4.42-2.69 5.4-5.25 5.68.41.36.78 1.06.78 2.13 0 1.54-.01 2.78-.01 3.16 0 .31.21.68.8.56C20.21 21.39 23.5 17.08 23.5 12 23.5 5.65 18.35.5 12 .5z"/></svg>
            </span>
            <div class="header-left">
                <h1>{$brandTitleEscaped}</h1>
                <h2>{$appTitle}</h2>
            </div>
        </div>
        <div class="header-right">
            <div class="header-actions">
                {$logoutButton}
            </div>
            {$langSwitcherHtml}
        </div>
    </header>
    {$errorHtml}
    {$dashboardNoticeHtml}
    {$dashboardNavHtml}
    <main class="dashboard-main">{$mainSection}</main>
</div>
<footer>
    <a href="https://github.com/jbsnewmedia/symfony-git-installer" target="_blank" class="footer-link">{$iconExternalLink} github.com/jbsnewmedia/symfony-git-installer</a>
</footer>
{$confirmationModal}
{$dropdownScript}
{$modalScript}
</body>
</html>
HTML;
}