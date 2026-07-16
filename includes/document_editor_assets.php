<?php
/**
 * includes/document_editor_assets.php
 * -----------------------------------
 * The ONE definition of the Summernote rich-text editor used by the Document
 * Writer — both for documents and for templates. Keeping it shared is deliberate:
 * this setup encodes fixes that were expensive to find, and a second copy would
 * inevitably drift back into them.
 *
 *  • The standalone justify* buttons crash Summernote 0.8.20 under jQuery 3.7
 *    ("t.append is not a function") and abort the whole init — which silently
 *    takes the dropdown handler down with it. Alignment lives inside the
 *    paragraph dropdown instead. Never add an ['align', [...]] group.
 *  • Summernote's bs5 build still emits Bootstrap-4 dropdown markup (data-toggle,
 *    never data-bs-toggle), so Bootstrap 5 never opens these menus. We open them
 *    with our own delegated handler and mark the open menu `vk-open` — NOT
 *    Bootstrap's `.show`, which header.php's global outside-click handler grabs
 *    and throws on (getInstance() returns null for non-Bootstrap menus).
 */

if (!function_exists('vk_document_editor_head')) {
    /** Editor stylesheet + the CSS our manual dropdown toggle depends on. */
    function vk_document_editor_head(): void
    {
        ?>
        <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs5.min.css" rel="stylesheet">
        <style>
            /* The manually toggled menus are marked vk-open (see note above). */
            .note-editor .note-dropdown-menu.vk-open { display: block; }
            /* Keep the font-family / size labels visible instead of collapsing to a caret. */
            .note-editor .note-btn.dropdown-toggle { min-width: 42px; }
        </style>
        <?php
    }
}

if (!function_exists('vk_document_editor_init')) {
    /**
     * Render the editor script for the given element selector.
     *
     * @param string $selector    jQuery selector of the editor element (e.g. '#docBody')
     * @param string $placeholder placeholder text (already localised)
     * @param int    $height      editor height in px
     */
    function vk_document_editor_init(string $selector, string $placeholder, int $height = 460): void
    {
        ?>
        <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-bs5.min.js"></script>
        <script>
        $(function () {
            $(<?= json_encode($selector) ?>).summernote({
                placeholder: <?= json_encode($placeholder) ?>,
                height: <?= (int) $height ?>,
                fontNames: ['Arial', 'Calibri', 'Cambria', 'Courier New', 'Georgia', 'Times New Roman', 'Verdana'],
                // Summernote hides fonts the current machine can't confirm are installed —
                // force the Windows-bundled ones to stay in the list on Linux / Mac.
                fontNamesIgnoreCheck: ['Calibri', 'Cambria'],
                toolbar: [
                    ['style', ['style']],
                    ['fontname', ['fontname']],
                    ['font', ['bold', 'italic', 'underline', 'strikethrough', 'superscript', 'subscript', 'clear']],
                    ['fontsize', ['fontsize']],
                    ['height', ['height']],
                    ['color', ['color']],
                    // NB: no ['align', [...]] group — the standalone justify* buttons
                    // crash init (see the file header). Alignment is in this dropdown.
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['table', ['table']],
                    ['insert', ['link', 'hr']],
                    ['history', ['undo', 'redo']],
                    ['view', ['codeview', 'fullscreen']]
                ]
            });

            // One delegated handler opens/closes the toolbar menus — no dependency on
            // Bootstrap's Dropdown component, so toolbar changes can never break it.
            // (Summernote builds each menu as the toggle's immediate next sibling.)
            function vkCloseDocMenus() {
                document.querySelectorAll('.note-editor .note-dropdown-menu.vk-open')
                    .forEach(function (m) { m.classList.remove('vk-open'); });
            }
            $(document).off('click.vkDoc').on('click.vkDoc', function (e) {
                var toggle = e.target.closest('.note-editor .note-btn.dropdown-toggle');
                if (toggle) {
                    e.preventDefault();
                    var menu = toggle.nextElementSibling;
                    var willOpen = menu && menu.classList.contains('note-dropdown-menu') && !menu.classList.contains('vk-open');
                    vkCloseDocMenus();               // close any other open menu first
                    if (willOpen) { menu.classList.add('vk-open'); }
                    return;
                }
                vkCloseDocMenus();                   // a click anywhere else closes the menus
            });
            // Choosing an item: Summernote stops the event bubbling, so close in the
            // capture phase (on the next tick, after it has applied the choice).
            document.addEventListener('click', function (e) {
                if (e.target.closest('.note-editor .note-dropdown-menu')) {
                    setTimeout(vkCloseDocMenus, 0);
                }
            }, true);
        });
        </script>
        <?php
    }
}
