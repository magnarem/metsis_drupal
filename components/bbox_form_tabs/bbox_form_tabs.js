(function (Drupal) {
  Drupal.behaviors.bboxFormTabs = {
    attach(context) {
      context.addEventListener("DOMContentLoaded", () => {
        const tabs = document.querySelectorAll('[role="tab"]');
        const panels = document.querySelectorAll('[role="tabpanel"]');

        tabs.forEach((tab) => {
          tab.addEventListener("click", () => {
            // Deactivate all tabs and panels.
            tabs.forEach((t) => {
              t.setAttribute("aria-selected", "false");
              t.setAttribute("tabindex", "-1");
              t.classList.remove("is-active");
            });
            panels.forEach((panel) => panel.setAttribute("hidden", true));

            // Activate the clicked tab and its panel.
            tab.setAttribute("aria-selected", "true");
            tab.setAttribute("tabindex", "0");
            tab.classList.add("is-active");
            document
              .getElementById(tab.getAttribute("aria-controls"))
              .removeAttribute("hidden");
          });

          // Add keyboard support.
          tab.addEventListener("keydown", (event) => {
            let newTab;
            if (event.key === "ArrowLeft" || event.key === "ArrowUp") {
              newTab =
                tabs[
                  (Array.prototype.indexOf.call(tabs, tab) - 1 + tabs.length) %
                    tabs.length
                ];
            } else if (
              event.key === "ArrowRight" ||
              event.key === "ArrowDown"
            ) {
              newTab =
                tabs[
                  (Array.prototype.indexOf.call(tabs, tab) + 1) % tabs.length
                ];
            } else if (event.key === "Home") {
              newTab = tabs[0];
            } else if (event.key === "End") {
              newTab = tabs[tabs.length - 1];
            }

            if (newTab) {
              newTab.focus();
              newTab.click();
              event.preventDefault();
            }
          });
        });
      });
    },
  };
})(Drupal);
