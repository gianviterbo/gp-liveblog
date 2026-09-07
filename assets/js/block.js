/* GP Liveblog — Gutenberg block wrapper ([gp_liveblog] shortcode). */
(function (wp) {
  'use strict';
  var el = wp.element.createElement;
  var registerBlockType = wp.blocks.registerBlockType;
  var InspectorControls = wp.blockEditor.InspectorControls;
  var TextControl = wp.components.TextControl;
  var ToggleControl = wp.components.ToggleControl;
  var PanelBody = wp.components.PanelBody;
  var __ = wp.i18n.__;

  registerBlockType('gp-liveblog/embed', {
    title: __('Liveblog embed', 'gp-liveblog'),
    description: __('Embed a liveblog feed — collapsible coverage widget.', 'gp-liveblog'),
    icon: 'video-alt3',
    category: 'embed',
    attributes: { id: { type: 'number', default: 0 }, collapsed: { type: 'boolean', default: true } },
    edit: function (props) {
      var atts = props.attributes;
      function set(extra) { props.setAttributes(extra); }
      var help = atts.id
        ? __('Liveblog #' + atts.id + ' will render on the page.', 'gp-liveblog')
        : __('Enter the liveblog ID from the Liveblogs screen.', 'gp-liveblog');
      return el('div', { className: 'gplb-block-edit', style: { padding: '12px', border: '1px dashed #f97316', borderRadius: '8px' } },
        el('strong', null, '🔴 ' + __('Liveblog embed', 'gp-liveblog')),
        el(InspectorControls, null,
          el(PanelBody, { title: __('Liveblog settings', 'gp-liveblog') },
            el(TextControl, { label: __('Liveblog ID', 'gp-liveblog'), type: 'number', value: atts.id || '', onChange: function (v) { set({ id: parseInt(v, 10) || 0 }); }, help: help }),
            el(ToggleControl, { label: __('Collapsed by default', 'gp-liveblog'), checked: !!atts.collapsed, onChange: function (v) { set({ collapsed: v }); } })
          )
        ),
        el('p', { style: { margin: '8px 0 0', color: '#8b949e' } }, __('Visitors open it via the LIVE button; editors see the composer.', 'gp-liveblog'))
      );
    },
    save: function () { return null; } // dynamic render_callback
  });
})(window.wp);
