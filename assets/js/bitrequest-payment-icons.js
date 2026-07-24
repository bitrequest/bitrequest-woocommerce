/* Bitrequest for WooCommerce — injects coin icons into the WooCommerce
   payments overview list. Markup is passed in via wp_localize_script. */
(function() {
    var icons = (window.BR_PAY_ICONS && BR_PAY_ICONS.html) || '';
    if (!icons) return;

    function tryInject() {
        // Walk all text nodes — finds the title in both classic and React-rendered lists
        var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
        var node, found = null;
        while ((node = walker.nextNode())) {
            if (node.textContent.trim() === 'Bitrequest' && !node.parentElement.dataset.brDone) {
                found = node.parentElement;
                break;
            }
        }
        if (!found) return false;
        found.dataset.brDone = '1';

        // Find the nearest list-item or card container
        var card = found.closest('li') || found.closest('[class]');
        if (!card) card = found.parentElement;

        // Find the description paragraph inside the card
        var desc = card.querySelector('p');
        if (!desc) return false;

        // Append icons on a new line below the description
        var wrap = document.createElement('span');
        wrap.style.cssText = 'display:block;margin-top:5px;line-height:1.6';
        wrap.innerHTML = icons;
        desc.insertAdjacentElement('afterend', wrap);
        return true;
    }

    // Use MutationObserver for React async rendering
    var injected = false;
    var observer = new MutationObserver(function() {
        if (!injected && tryInject()) {
            injected = true;
            observer.disconnect();
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });

    // Also try immediately and stop observing after 10s
    if (tryInject()) { injected = true; observer.disconnect(); }
    setTimeout(function() { observer.disconnect(); }, 10000);
})();
