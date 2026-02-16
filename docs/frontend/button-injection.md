# Button Injection System

The button injection system automatically adds download buttons to WordPress image blocks with advanced theme compatibility.

## Detection Methods

**Multi-Layer Detection**
Robust attachment ID detection for various theme implementations:

1. **Image Class Detection**: `wp-image-123`
2. **Picture Element Classes**: Theme responsive images  
3. **Figure Element Classes**: Gutenberg block containers
4. **Data Attributes**: Custom theme implementations

```javascript
// Primary detection method
var attachmentMatch = imgClasses.match(/wp-image-(\d+)/);

// Picture element fallback (responsive themes)
if (!attachmentMatch) {
    var $picture = $figure.find('picture');
    attachmentMatch = pictureClasses.match(/wp-image-(\d+)/);
}
```

## Button HTML Generation

**Dynamic Button Creation**
```javascript
function createButtonHtml(postId, attachmentId, imgSrc, imgWidth, imgHeight) {
    return '<button class="smi-get-button" ' +
           'data-post-id="' + postId + '" ' +
           'data-attachment-id="' + attachmentId + '" ' +
           'data-src="' + imgSrc + '" ' +
           'data-width="' + imgWidth + '" ' +
           'data-height="' + imgHeight + '">' +
           '<span class="smi-button-text">Download Hi-Res</span>' +
           '</button>';
}
```

**Data Attributes**
Essential data for modal interaction:
- `data-post-id`: WordPress post identifier
- `data-attachment-id`: Media library image ID
- `data-src`: Original image URL
- `data-width`/`data-height`: Dimensions for calculation

## Performance Optimization

**Smart Filtering**
Prevents unnecessary button injection:
```javascript
// Skip very small images (likely icons)
if (imgWidth < 100 || imgHeight < 100) {
    return;
}

// Skip if button already exists
if ($figure.find('.smi-get-button').length) {
    return;
}
```

**Efficient DOM Traversal**
- Single-pass image block detection
- Cached jQuery selectors
- Minimal DOM manipulation
- Lazy loading compatibility

## Dynamic Content Support

**MutationObserver Integration**
Automatic detection of new content:
```javascript
var observer = new MutationObserver(function(mutations) {
    var debounceId = null;
    if (debounceId) clearTimeout(debounceId);
    debounceId = setTimeout(function() {
        self.injectButtons(container);
    }, 120);
});
observer.observe(container, { childList: true, subtree: true });
```

**Event System**
Manual trigger support for themes:
```javascript
// jQuery event
$(document).trigger('smi:refreshButtons', { root: '#post-grid' });

// Native CustomEvent
document.dispatchEvent(new CustomEvent('smi:refreshButtons', {
    detail: { root: '#post-grid' }
}));
```

## Theme Compatibility

**Common Integration Points**
- Gallery plugins and sliders
- Lazy loading systems
- Responsive image techniques
- Custom post type displays

**Compatibility Layers**
```javascript
// Gallery container observers
var commonSelectors = [
    '#post-grid',
    '.image-gallery',
    '.wp-block-gallery',
    '.elementor-gallery'
];

commonSelectors.forEach(selector => {
    tryObserve(selector);
});
```

## Error Handling

**Graceful Degradation**
- Continue processing other images if one fails
- Log warnings without breaking functionality
- Validate data attributes after creation
- Fallback to manual triggers

**Debug Information**
Inspect the rendered DOM in the browser when troubleshooting detection and button injection issues.

## External Integration

**PHP Helper Method**
For custom theme integration:
```php
// Generate button HTML for custom contexts
$button_html = \SellMyImages\Content\BlockProcessor::generate_external_button_html(
    $attachment_id,
    $post_id
);
echo $button_html;
```

**Filter Support**
Customization hooks:
- `smi_button_text` - Customize button label
- `smi_load_assets` - Control asset loading
- `smi_min_image_size` - Adjust size threshold
