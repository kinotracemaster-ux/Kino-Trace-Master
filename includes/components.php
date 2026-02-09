<?php
/**
 * Componentes HTML Reutilizables
 * 
 * Centraliza la generación de elementos UI comunes para evitar
 * duplicación de código HTML en todos los módulos.
 */

/**
 * Genera un botón con icono
 */
function render_button($text, $type = 'primary', $icon = null, $attributes = [])
{
    $classes = ['btn', "btn-$type"];
    if (isset($attributes['class'])) {
        $classes[] = $attributes['class'];
        unset($attributes['class']);
    }

    $attrs = 'class="' . implode(' ', $classes) . '"';
    foreach ($attributes as $key => $value) {
        $attrs .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
    }

    $content = '';
    if ($icon) {
        $content .= $icon . ' ';
    }
    $content .= htmlspecialchars($text);

    return "<button $attrs>$content</button>";
}

/**
 * Genera una tarjeta de estadística
 */
function render_stat_card($icon, $value, $label)
{
    return <<<HTML
    <div class="stat-card">
        <div class="stat-icon">
            $icon
        </div>
        <div class="stat-content">
            <div class="stat-value">{$value}</div>
            <div class="stat-label">{$label}</div>
        </div>
    </div>
HTML;
}

/**
 * Genera un badge
 */
function render_badge($text, $type = 'primary')
{
    $text = htmlspecialchars($text);
    return "<span class=\"badge badge-$type\">$text</span>";
}

/**
 * Genera un code tag
 */
function render_code_tag($code)
{
    $code = htmlspecialchars($code);
    return "<span class=\"code-tag\">$code</span>";
}

/**
 * Genera un grupo de formulario
 */
function render_form_group($label, $input, $help = null)
{
    $helpHtml = $help ? "<small class=\"text-muted\">$help</small>" : '';
    return <<<HTML
    <div class="form-group">
        <label class="form-label">{$label}</label>
        {$input}
        {$helpHtml}
    </div>
HTML;
}

/**
 * Genera un input de formulario
 */
function render_input($name, $type = 'text', $attributes = [])
{
    $attrs = [
        'type' => $type,
        'name' => $name,
        'id' => $name,
        'class' => 'form-input'
    ];

    $attrs = array_merge($attrs, $attributes);

    $attrString = '';
    foreach ($attrs as $key => $value) {
        $attrString .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
    }

    return "<input$attrString>";
}

/**
 * Genera un select de formulario
 */
function render_select($name, $options, $selected = null, $attributes = [])
{
    $attrs = [
        'name' => $name,
        'id' => $name,
        'class' => 'form-select'
    ];

    $attrs = array_merge($attrs, $attributes);

    $attrString = '';
    foreach ($attrs as $key => $value) {
        $attrString .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
    }

    $optionsHtml = '';
    foreach ($options as $value => $label) {
        $selectedAttr = ($value == $selected) ? ' selected' : '';
        $optionsHtml .= '<option value="' . htmlspecialchars($value) . '"' . $selectedAttr . '>' . htmlspecialchars($label) . '</option>';
    }

    return "<select$attrString>$optionsHtml</select>";
}

/**
 * Genera un textarea
 */
function render_textarea($name, $value = '', $attributes = [])
{
    $attrs = [
        'name' => $name,
        'id' => $name,
        'class' => 'form-textarea'
    ];

    $attrs = array_merge($attrs, $attributes);

    $attrString = '';
    foreach ($attrs as $key => $val) {
        $attrString .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($val) . '"';
    }

    return "<textarea$attrString>" . htmlspecialchars($value) . "</textarea>";
}

/**
 * Genera un estado vacío
 */
function render_empty_state($icon, $title, $text, $action = null)
{
    $actionHtml = $action ?? '';
    return <<<HTML
    <div class="empty-state">
        <div class="empty-state-icon">
            $icon
        </div>
        <div class="empty-state-title">$title</div>
        <div class="empty-state-text">$text</div>
        $actionHtml
    </div>
HTML;
}

/**
 * Genera un spinner de carga
 */
function render_loading($text = 'Cargando...')
{
    return <<<HTML
    <div class="loading">
        <div class="spinner"></div>
        <p class="text-muted mt-3">$text</p>
    </div>
HTML;
}

/**
 * Genera tabs
 */
function render_tabs($tabs, $activeTab = null)
{
    $activeTab = $activeTab ?? array_key_first($tabs);

    $tabButtons = '';
    foreach ($tabs as $id => $label) {
        $active = ($id === $activeTab) ? ' active' : '';
        $tabButtons .= "<button class=\"tab$active\" data-tab=\"$id\">$label</button>";
    }

    return "<div class=\"tabs\">$tabButtons</div>";
}

/**
 * Genera encabezado de card
 */
function render_card_header($title, $actions = null)
{
    $actionsHtml = $actions ?? '';
    return <<<HTML
    <div class="card-header">
        <h3 class="card-title">$title</h3>
        $actionsHtml
    </div>
HTML;
}

/**
 * Genera una tabla responsive
 */
function render_table($headers, $rows, $classes = '')
{
    $theadHtml = '<tr>';
    foreach ($headers as $header) {
        $theadHtml .= '<th>' . htmlspecialchars($header) . '</th>';
    }
    $theadHtml .= '</tr>';

    $tbodyHtml = '';
    foreach ($rows as $row) {
        $tbodyHtml .= '<tr>';
        foreach ($row as $cell) {
            $tbodyHtml .= '<td>' . $cell . '</td>';
        }
        $tbodyHtml .= '</tr>';
    }

    return <<<HTML
    <div class="table-container">
        <table class="table $classes">
            <thead>$theadHtml</thead>
            <tbody>$tbodyHtml</tbody>
        </table>
    </div>
HTML;
}

/**
 * Genera iconos SVG comunes
 */
class Icons
{
    public static function search()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>';
    }

    public static function upload()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>';
    }

    public static function document()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>';
    }

    public static function code()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>';
    }

    public static function check()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
    }

    public static function x()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>';
    }

    public static function edit()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>';
    }

    public static function trash()
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>';
    }
}
