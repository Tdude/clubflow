(function () {
  function initListItems() {
    var items = document.querySelectorAll('[data-clubflow-list-item]');
    items.forEach(function (item) {
      var toggle = item.querySelector('[data-clubflow-list-toggle]');
      var content = item.querySelector('[data-clubflow-list-content]');
      if (!toggle || !content) {
        return;
      }
      toggle.addEventListener('click', function (e) {
        e.preventDefault();
        var isExpanded = item.classList.contains('is-expanded');
        item.classList.toggle('is-expanded');
        content.setAttribute('aria-hidden', isExpanded ? 'true' : 'false');
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initListItems);
  } else {
    initListItems();
  }
})();
