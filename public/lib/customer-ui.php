<?php
/**
 * Shared presentation for every customer-facing screen: the client area, the
 * website brief wizard and the small message pages. It holds the one customer
 * stylesheet and a few markup helpers. It does not read or write any data.
 * The admin dashboard has its own styles and does not use this file.
 */

declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

/**
 * A passive status label. Tone is '' (neutral) or 'accent'.
 */
function webco_ui_badge(string $text, string $tone = '', string $extraClass = ''): string
{
    $class = 'badge' . ($tone === 'accent' ? ' badge-accent' : '') . ($extraClass !== '' ? ' ' . $extraClass : '');

    return '<span class="' . $class . '">' . webco_html($text) . '</span>';
}

/**
 * Short file-type tag taken from the file name, for example PNG or PDF.
 */
function webco_ui_file_type(string $name): string
{
    $extension = strtoupper((string) pathinfo($name, PATHINFO_EXTENSION));
    $extension = preg_replace('/[^A-Z0-9]/', '', $extension);
    if (!is_string($extension) || $extension === '') {
        return 'FILE';
    }

    return substr($extension, 0, 4);
}

/**
 * One saved file. Long names wrap instead of widening the page.
 */
function webco_ui_file_row(string $name, string $meta = ''): string
{
    $html = '<li class="file-row"><span class="file-type" aria-hidden="true">'
        . webco_html(webco_ui_file_type($name)) . '</span><span class="file-info"><span class="file-name">'
        . webco_html($name) . '</span>';
    if ($meta !== '') {
        $html .= '<span class="file-meta">' . webco_html($meta) . '</span>';
    }

    return $html . '</span></li>';
}

function webco_customer_css(): string
{
    return <<<'CSS'
      :root {
        color-scheme: light;
        --bg: #f6f5f1;
        --card: #fff;
        --soft: #faf9f5;
        --ink: #0e1b20;
        --ink-2: #34444d;
        --muted: #56656d;
        --line: #e0ddd4;
        --line-2: #cbc7bb;
        --field: #8a9791;
        --green: #0c6b62;
        --green-d: #08524b;
        --green-t: #e5f3f1;
        --green-l: #b9dbd6;
        --focus: #0a7f72;
        --warn: #8a3b2a;
        --r-card: 14px;
        --r-ctl: 10px;
        --gutter: 1.25rem;
        --gap: 0.9rem;
        --serif: Georgia, "Iowan Old Style", Palatino, "Times New Roman", serif;
        --sans: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      }
      *, *::before, *::after { box-sizing: border-box; }
      [hidden] { display: none !important; }
      html { -webkit-text-size-adjust: 100%; }
      body { margin: 0; background: var(--bg); color: var(--ink); font: 1.0625rem/1.55 var(--sans); overflow-wrap: break-word; }
      main { width: 100%; max-width: 63rem; margin: 0 auto; padding: 1.25rem var(--gutter) 3rem; }
      .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

      /* Type */
      h1, h2, h3 { margin: 0; color: var(--ink); font-family: var(--serif); font-weight: 600; letter-spacing: -0.01em; overflow-wrap: anywhere; }
      h1 { font-size: 2.125rem; line-height: 1.1; }
      h2 { font-size: 1.5rem; line-height: 1.2; }
      h3 { font-size: 1.1875rem; line-height: 1.3; letter-spacing: 0; }
      p { margin: 0.6rem 0 0; }
      a { color: var(--green); text-underline-offset: 0.18em; }
      a:hover { color: var(--green-d); }
      .eyebrow, .brand { margin: 0; color: var(--green); font: 700 0.8125rem/1.2 var(--sans); letter-spacing: 0.08em; text-transform: uppercase; }
      .brand span { color: var(--muted); font-weight: 600; }
      .lead { color: var(--ink-2); }
      .note, .meta, .reassurance { color: var(--muted); }
      .meta { font-size: 0.9375rem; }
      .hint { margin-top: 0.25rem; color: var(--muted); font-size: 0.9375rem; line-height: 1.45; }
      .summary { white-space: pre-wrap; overflow-wrap: anywhere; }
      .section-title { margin: 1.6rem 0 0; }
      .page-head { margin-bottom: 0.25rem; }
      .page-head .lead { margin-top: 0.5rem; }
      .page-head .brand + h1 { margin-top: 0.5rem; }
      .ref { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; font-size: 0.875rem; overflow-wrap: anywhere; }

      /* Cards, notices, badges */
      .card { min-width: 0; margin-top: var(--gap); padding: 1rem; background: var(--card); border: 1px solid var(--line); border-radius: var(--r-card); }
      .card > h2:first-child { margin-top: 0; }
      .card-quiet { background: var(--soft); }
      .card-quiet h2 { font-size: 1.1875rem; }
      .notice { margin: 0 0 var(--gap); padding: 0.6rem 0.85rem; border: 1px solid var(--green-l); border-radius: var(--r-ctl); background: var(--green-t); font-size: 0.9375rem; font-weight: 600; line-height: 1.45; }
      .status-line { margin: 0.9rem 0 0; padding: 0.6rem 0.85rem; border: 1px solid var(--green-l); border-radius: var(--r-ctl); background: var(--green-t); font-weight: 600; line-height: 1.4; }
      .status-line strong { display: block; }
      .status-line span { display: block; margin-top: 0.15rem; color: var(--ink-2); font-size: 0.9375rem; font-weight: 400; }
      .badge { display: inline-flex; align-items: center; min-height: 1.5rem; padding: 0 0.55rem; border: 1px solid var(--line-2); border-radius: 999px; background: #f3f1ea; color: var(--ink-2); font: 600 0.8125rem/1 var(--sans); white-space: nowrap; }
      .badge-accent { border-color: var(--green-l); background: var(--green-t); color: var(--green-d); }
      .badges { display: flex; flex-wrap: wrap; gap: 0.35rem; }
      .grid-2 { display: grid; gap: var(--gap); margin-top: var(--gap); align-items: start; }
      .grid-2 > .card { margin-top: 0; }
      .wiz-head .status-line { border-color: var(--line); background: var(--soft); }

      /* Buttons */
      .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; min-height: 2.75rem; padding: 0.5rem 1.15rem; border: 1px solid transparent; border-radius: var(--r-ctl); font: 600 1rem/1.2 var(--sans); text-align: center; text-decoration: none; cursor: pointer; transition: background-color 0.15s, border-color 0.15s, color 0.15s; }
      .btn-primary { border-color: var(--green); background: var(--green); color: #fff; }
      .btn-primary:hover { border-color: var(--green-d); background: var(--green-d); color: #fff; }
      .btn-secondary { border-color: var(--line-2); background: #fff; color: var(--ink); }
      .btn-secondary:hover { border-color: var(--green); background: var(--soft); color: var(--green-d); }
      .btn:disabled, .btn[disabled] { opacity: 0.5; cursor: not-allowed; }
      .btn-sm { min-height: 2.5rem; padding: 0.35rem 0.9rem; font-size: 0.9375rem; }
      .actions { display: flex; flex-wrap: wrap; gap: 0.6rem; margin-top: 1rem; }
      .actions .btn { flex: 1 1 12rem; }
      :focus-visible { outline: 3px solid var(--focus); outline-offset: 2px; }

      /* Forms */
      label { display: block; margin-top: 1.1rem; font-weight: 650; line-height: 1.35; }
      label + .hint { margin-top: 0.15rem; }
      input[type="text"], input[type="tel"], input[type="email"], select, textarea { display: block; width: 100%; max-width: 100%; margin-top: 0.4rem; padding: 0.6rem 0.75rem; border: 1px solid var(--field); border-radius: var(--r-ctl); background: #fff; color: var(--ink); font: inherit; font-size: 1rem; line-height: 1.5; }
      select, input[type="text"], input[type="tel"], input[type="email"] { min-height: 2.75rem; }
      textarea { resize: vertical; }
      input:focus, select:focus, textarea:focus { border-color: var(--green); }
      input[type="radio"], input[type="checkbox"] { width: 1.15rem; height: 1.15rem; margin: 0; accent-color: var(--green); }
      fieldset.call { min-width: 0; margin: 1.25rem 0 0; padding: 0.8rem 0.9rem 1rem; border: 1px solid var(--line); border-radius: 12px; background: var(--soft); }
      fieldset.call legend { padding: 0 0.3rem; font-weight: 650; }
      label.choice { display: inline-flex; align-items: center; gap: 0.5rem; min-height: 2.75rem; margin: 0.4rem 0.5rem 0 0; padding: 0 0.9rem; border: 1px solid var(--line-2); border-radius: var(--r-ctl); background: #fff; font-weight: 600; cursor: pointer; }
      label.choice:has(input:checked) { border-color: var(--green); background: var(--green-t); }
      .call-extra { display: none; }
      fieldset.call:has(input[value="yes"]:checked) .call-extra { display: block; }
      input[type="file"] { display: block; width: 100%; max-width: 100%; margin-top: 0.4rem; padding: 0.5rem; border: 1px dashed var(--field); border-radius: var(--r-ctl); background: var(--soft); color: var(--muted); font: inherit; font-size: 0.9375rem; cursor: pointer; }
      input[type="file"]::file-selector-button { min-height: 2.5rem; margin: 0 0.75rem 0 0; padding: 0 1rem; border: 1px solid var(--line-2); border-radius: 8px; background: #fff; color: var(--ink); font: 600 0.9375rem var(--sans); cursor: pointer; }
      input[type="file"]:hover::file-selector-button { border-color: var(--green); color: var(--green-d); }
      .form-actions { margin-top: 1.25rem; }
      .form-actions .btn { width: 100%; }

      /* Files */
      .files { display: grid; gap: 0.5rem; margin: 0.7rem 0 0; padding: 0; list-style: none; }
      .file-row { display: flex; align-items: center; gap: 0.75rem; min-width: 0; padding: 0.55rem 0.7rem; border: 1px solid var(--line); border-radius: var(--r-ctl); background: var(--soft); }
      .file-type { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; min-width: 2.5rem; height: 2.25rem; padding: 0 0.35rem; border-radius: 8px; background: var(--green-t); color: var(--green-d); font: 700 0.75rem/1 var(--sans); letter-spacing: 0.04em; }
      .file-info { display: flex; flex-direction: column; min-width: 0; }
      .file-name { font-weight: 600; line-height: 1.3; overflow-wrap: anywhere; }
      .file-meta { color: var(--muted); font-size: 0.875rem; line-height: 1.35; }
      .file-section { padding: 1rem 0; border-top: 1px solid var(--line); }
      .file-section:first-child { padding-top: 0; border-top: 0; }
      .file-section:last-child { padding-bottom: 0; }
      .file-section-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 0.25rem 0.75rem; }
      .file-section-head .meta { margin: 0; }
      .file-section .meta { margin-top: 0.4rem; }
      .upload-group { margin-top: 0.9rem; padding: 0.8rem 0.9rem 0.95rem; border: 1px solid var(--line); border-radius: 12px; background: #fff; }
      .upload-group > .meta { margin-top: 0; }
      form.upload { margin-top: 0.75rem; }
      form.upload label { margin-top: 0; }
      [data-upload-status] p { margin: 0.4rem 0 0; font-size: 0.9375rem; }
      .upload-ok { color: var(--green); font-weight: 650; }
      .upload-fail { color: var(--warn); font-weight: 650; }

      /* Client area header and navigation */
      .client-bar { display: flex; flex-direction: column; gap: 0.6rem; margin-bottom: 1.1rem; }
      .client-nav { display: flex; gap: 0.1rem; padding: 0.25rem; overflow-x: auto; border: 1px solid var(--line); border-radius: 12px; background: #fff; scrollbar-width: none; }
      .client-nav::-webkit-scrollbar { display: none; }
      .client-nav a:focus-visible { outline-offset: -3px; }
      .client-nav a { flex: 1 0 auto; display: inline-flex; align-items: center; justify-content: center; min-height: 2.5rem; padding: 0 0.6rem; border-radius: 9px; color: var(--ink-2); font-size: 0.9375rem; font-weight: 600; text-decoration: none; white-space: nowrap; }
      .client-nav a:hover { background: var(--green-t); color: var(--green-d); }
      .client-nav a[aria-current="page"] { background: var(--green); color: #fff; }

      /* Home */
      .project h1 { overflow-wrap: anywhere; }
      .facts { display: grid; gap: 0.5rem; margin: 1rem 0 0; }
      .facts div { display: grid; grid-template-columns: 7.5rem minmax(0, 1fr); gap: 0.75rem; min-width: 0; }
      .facts dt { color: var(--muted); font-size: 0.9375rem; }
      .facts dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
      .kv { display: flex; flex-wrap: wrap; gap: 0.15rem 1.25rem; margin: 0; font-size: 0.9375rem; }
      .kv div { display: flex; flex-wrap: wrap; gap: 0 0.4rem; min-width: 0; }
      .kv dt { color: var(--muted); }
      .kv dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
      .call-line { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem 0.75rem; margin: 0.9rem 0 0; padding-top: 0.8rem; border-top: 1px solid var(--line); }
      .tracker { margin: 1rem 0 0; padding: 0; list-style: none; counter-reset: stage; }
      .tracker li { position: relative; display: grid; grid-template-columns: 1.75rem minmax(0, 1fr); column-gap: 0.75rem; padding-bottom: 1rem; counter-increment: stage; }
      .tracker li:last-child { padding-bottom: 0; }
      .tracker li::before { content: counter(stage); grid-column: 1; grid-row: 1; z-index: 1; display: flex; align-items: center; justify-content: center; width: 1.75rem; height: 1.75rem; border: 2px solid var(--line-2); border-radius: 50%; background: #fff; color: var(--muted); font: 700 0.8125rem/1 var(--sans); }
      .tracker li::after { content: ""; position: absolute; top: 1.75rem; bottom: 0; left: calc(0.875rem - 1px); width: 2px; background: var(--line); }
      .tracker li:last-child::after { display: none; }
      .tracker li.done::before { content: "\2713"; border-color: var(--green); background: var(--green); color: #fff; }
      .tracker li.done::after { background: var(--green); }
      .tracker li.current::before { border-color: var(--green); background: var(--green-t); color: var(--green-d); }
      .stage-body { grid-column: 2; display: flex; flex-wrap: wrap; align-items: center; gap: 0.1rem 0.6rem; min-height: 1.75rem; }
      .stage-label { color: var(--ink-2); font-weight: 600; }
      .tracker li.current .stage-label { color: var(--ink); font-weight: 700; }
      .stage-state { color: var(--muted); font-size: 0.8125rem; font-weight: 600; }
      .action-grid { display: grid; gap: 0.6rem; margin-top: 0.75rem; }
      .action { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; min-height: 3.5rem; min-width: 0; padding: 0.75rem 1rem; border: 1px solid var(--line); border-radius: var(--r-card); background: #fff; color: var(--ink); text-decoration: none; transition: border-color 0.15s, background-color 0.15s; }
      .action::after { content: "\2192"; content: "\2192" / ""; flex: 0 0 auto; color: var(--green); font-weight: 700; }
      .action:hover { border-color: var(--green); color: var(--ink); }
      .action-text { display: flex; flex-direction: column; min-width: 0; }
      .action-text strong { font-weight: 650; line-height: 1.3; }
      .action-text span { color: var(--muted); font-size: 0.9375rem; line-height: 1.35; }
      .action-primary { border-color: var(--green); background: var(--green); color: #fff; }
      .action-primary:hover { border-color: var(--green-d); background: var(--green-d); color: #fff; }
      .action-primary::after { color: #fff; }
      .action-primary .action-text span { color: #d9eeeb; }
      .grid-split { display: grid; gap: var(--gap); margin-top: var(--gap); align-items: start; }
      .grid-split > .card { margin-top: 0; }
      .more-link { font-weight: 600; }

      /* Requests */
      .request-list { display: grid; margin-top: 0.75rem; }
      .request { min-width: 0; padding: 0.95rem 0; border-top: 1px solid var(--line); }
      .request:first-child { padding-top: 0; border-top: 0; }
      .request:last-child { padding-bottom: 0; }
      .request-list.is-cards { gap: 0.75rem; margin-top: var(--gap); }
      .request-list.is-cards .request { padding: 1rem; border: 1px solid var(--line); border-radius: var(--r-card); background: #fff; }
      .request-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 0.4rem 0.75rem; }
      .request-id { min-width: 0; }
      .request-date { margin: 0.1rem 0 0; }
      .request-text { margin-top: 0.6rem; }
      .request-call { margin-top: 0.75rem; padding: 0.6rem 0.8rem; border-radius: var(--r-ctl); background: #f4f2ec; }
      .label-sm { margin: 0; color: var(--muted); font-size: 0.8125rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; }
      .request-call .kv { margin-top: 0.3rem; }
      .attachments { margin-top: 0.9rem; }
      .attachments .files { margin-top: 0.4rem; }
      .attachments .meta { margin-top: 0.3rem; }
      .add-files { margin-top: 0.9rem; }
      .add-files summary { display: inline-flex; list-style: none; }
      .add-files summary::-webkit-details-marker { display: none; }
      .add-files[open] summary { margin-bottom: 0.2rem; }

      /* Submitted brief and review */
      .callout { display: flex; flex-direction: column; gap: 0.75rem; }
      .callout p { margin: 0; }
      .doc { margin-top: var(--gap); overflow: hidden; border: 1px solid var(--line); border-radius: var(--r-card); background: #fff; }
      .doc-section { padding: 1.1rem 1rem 1.2rem; border-top: 1px solid var(--line); }
      .doc-section:first-child { border-top: 0; }
      .qa { display: grid; gap: 0.9rem; margin: 0.8rem 0 0; }
      .qa > div { min-width: 0; }
      .qa dt { color: var(--muted); font-size: 0.9375rem; font-weight: 650; line-height: 1.4; }
      .qa dd { margin: 0.1rem 0 0; white-space: pre-wrap; overflow-wrap: anywhere; }
      .review-section { padding: 1rem 0; border-top: 1px solid var(--line); }
      .review-head { display: flex; align-items: baseline; justify-content: space-between; gap: 0.75rem; }
      .review-head a { font-weight: 600; }
      .wizard .review-head h3 { margin-top: 0; }
      .review-files-label { margin-top: 0.9rem; }
      .review-section .files { margin-top: 0.5rem; }
      .wiz-help { margin-top: 1.25rem; }

      /* Billing */
      .billing form { display: grid; gap: 0.6rem; margin-top: 1rem; }
      .billing .btn { width: 100%; }
      .placeholder-list { margin: 0.9rem 0 0; padding: 0; list-style: none; }
      .placeholder-list li { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; margin-top: 0.5rem; padding: 0.65rem 0.8rem; border: 1px dashed var(--line-2); border-radius: var(--r-ctl); }

      /* Brief wizard */
      .wiz-head { margin-bottom: 1rem; }
      .wiz-brand { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 0.25rem 1rem; }
      .wiz-brand .ref { color: var(--muted); font-size: 0.8125rem; }
      .wiz-head h1 { margin-top: 0.5rem; }
      .wiz-line { display: flex; flex-wrap: wrap; align-items: center; gap: 0.35rem 0.6rem; margin-top: 0.5rem; }
      .step-count { margin: 0; color: var(--green); font: 700 0.8125rem/1.2 var(--sans); letter-spacing: 0.06em; text-transform: uppercase; }
      .wizard h2 { margin-top: 0.35rem; }
      .steps { display: flex; gap: 0.25rem; margin: 0 0 var(--gap); padding: 0; list-style: none; counter-reset: step; }
      .steps li { position: relative; flex: 1; height: 0.375rem; border-radius: 999px; background: var(--line-2); counter-increment: step; }
      .steps li.done { background: var(--green-l); }
      .steps li.current { background: var(--green); }
      .step-mark { display: none; }
      .step-name { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
      .step-layout { display: flex; flex-direction: column; }
      .step-copy { min-width: 0; }
      .step-copy > .hint:first-child { margin-top: 0.7rem; color: var(--ink-2); font-size: 1rem; }
      .step-figure { order: -1; display: flex; align-items: center; gap: 0.75rem; margin: 0.75rem 0 0; }
      .step-figure img { flex: 0 0 auto; width: 5.5rem; height: 4rem; object-fit: cover; object-position: top; border: 1px solid var(--line); border-radius: 8px; }
      .step-figure figcaption { color: var(--muted); font-size: 0.875rem; line-height: 1.35; }
      .wizard h3 { margin-top: 1.5rem; }
      .pair-list { margin-top: 0.4rem; }
      .slot { margin-top: 0.9rem; padding: 0.85rem 0.9rem 1rem; border: 1px solid var(--line); border-radius: 12px; background: var(--soft); }
      .slot-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.5rem 0.75rem; }
      .slot-title { margin: 0; font-weight: 700; }
      .slot label:first-of-type { margin-top: 0.6rem; }
      .add-pair { width: 100%; margin-top: 0.9rem; }
      .pair-status:empty { margin: 0; }
      .dock { position: sticky; bottom: 0; display: flex; gap: 0.6rem; margin-top: 1rem; padding: 0.75rem 0 0.1rem; border-top: 1px solid var(--line); background: #fff; }
      .dock .btn-primary { flex: 1; }
      .dock .btn-secondary { min-width: 5.5rem; }

      @media (max-width: 23.4375rem) { .client-nav a { padding: 0 0.5rem; font-size: 0.875rem; } }
      @media (min-width: 36rem) {
        .action-grid { grid-template-columns: 1fr 1fr; }
        .action-primary { grid-column: 1 / -1; }
        .callout { flex-direction: row; align-items: center; justify-content: space-between; }
        .callout .btn { flex: 0 0 auto; }
      }
      @media (min-width: 40rem) {
        :root { --gutter: 1.5rem; --gap: 1rem; }
        main { padding-top: 1.75rem; padding-bottom: 4rem; }
        .card { padding: 1.35rem 1.5rem 1.45rem; }
        .client-bar { flex-direction: row; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; }
        .client-nav { flex: 0 0 auto; overflow: visible; }
        .client-nav a { flex: 0 0 auto; min-height: 2.25rem; padding: 0 1rem; }
        .facts { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.75rem 1.5rem; }
        .facts div { display: block; }
        .facts dt { font-size: 0.875rem; }
        .facts dd { margin-top: 0.1rem; }
        .facts.facts-rows { grid-template-columns: minmax(0, 1fr); gap: 0.5rem; }
        .facts.facts-rows div { display: grid; }
        .actions .btn { flex: 0 0 auto; }
        .form-actions .btn { width: auto; }
        .billing form { margin-top: 1rem; }
      }
      @media (min-width: 48rem) {
        :root { --gutter: 2rem; }
        h1 { font-size: 2.75rem; }
        h2 { font-size: 1.75rem; }
        .grid-2 { grid-template-columns: 1fr 1fr; }
        .file-section .files { grid-template-columns: 1fr 1fr; }
        .doc-section { padding: 1.35rem 1.5rem 1.5rem; }
        .qa { gap: 0.8rem; }
        .qa > div { display: grid; grid-template-columns: 13.5rem minmax(0, 1fr); gap: 0.25rem 1.5rem; }
        .qa dd { margin-top: 0; }
        .tracker { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); }
        .tracker li { display: block; padding: 0 0.5rem 0 0; }
        .tracker li::before { margin-bottom: 0.6rem; }
        .tracker li::after { top: calc(0.875rem - 1px); right: 0.5rem; bottom: auto; left: 2.25rem; width: auto; height: 2px; }
        .stage-body { flex-direction: column; align-items: flex-start; min-height: 0; }
      }
      @media (min-width: 52rem) {
        .steps { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0.7rem 1rem; margin-bottom: 1.25rem; }
        .steps li { display: flex; align-items: flex-start; gap: 0.55rem; height: auto; border-radius: 0; background: none; color: var(--muted); font-size: 0.9375rem; line-height: 1.3; }
        .steps li.done, .steps li.current { background: none; }
        .steps li.done { color: var(--ink-2); }
        .steps li.current { color: var(--ink); font-weight: 700; }
        .step-mark { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; width: 1.5rem; height: 1.5rem; border: 2px solid var(--line-2); border-radius: 50%; background: #fff; color: var(--muted); font: 700 0.75rem/1 var(--sans); }
        .step-mark::before { content: counter(step); }
        .steps li.done .step-mark { border-color: var(--green); background: var(--green); color: #fff; }
        .steps li.done .step-mark::before { content: "\2713"; }
        .steps li.current .step-mark { border-color: var(--green); background: var(--green-t); color: var(--green-d); }
        .step-name { position: static; width: auto; height: auto; overflow: visible; clip: auto; white-space: normal; padding-top: 0.2rem; }
        .step-layout.has-figure { display: grid; grid-template-columns: minmax(0, 1fr) 17rem; gap: 1.5rem; align-items: start; }
        .step-figure { order: 0; display: block; margin-top: 0.7rem; }
        .step-figure img { width: 100%; height: auto; }
        .step-figure figcaption { margin-top: 0.4rem; }
        .dock { position: static; justify-content: space-between; padding-bottom: 0; background: transparent; }
        .dock .btn-primary { flex: 0 0 auto; min-width: 9rem; margin-left: auto; }
        .add-pair { width: auto; }
      }
      @media (min-width: 56rem) {
        .grid-split { grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr); }
      }
      @media (min-width: 60rem) {
        .action-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
      }
      @media (prefers-reduced-motion: reduce) {
        * { transition: none !important; }
      }
CSS;
}
