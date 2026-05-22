(function (Drupal, once) {
  Drupal.behaviors.bboxFormTabs = {
    attach(context) {
      once(
        "bbox-form-tabs",
        '[data-component-id="metsis_drupal:bbox_form_tabs"]',
        context,
      ).forEach((wrapper) => {
        const tabs = Array.from(wrapper.querySelectorAll('[role="tab"]'));
        const panels = Array.from(
          wrapper.querySelectorAll('[role="tabpanel"]'),
        );

        if (!tabs.length || !panels.length) {
          return;
        }

        const setActiveTab = (activeTab) => {
          tabs.forEach((tab) => {
            const isActive = tab === activeTab;
            tab.setAttribute("aria-selected", isActive ? "true" : "false");
            tab.setAttribute("tabindex", isActive ? "0" : "-1");
            tab.classList.toggle("is-active", isActive);
          });

          panels.forEach((panel) => {
            const isActive =
              panel.id === activeTab.getAttribute("aria-controls");
            panel.classList.toggle("is-active", isActive);
            panel.toggleAttribute("hidden", !isActive);
          });
        };

        tabs.forEach((tab) => {
          tab.addEventListener("click", () => {
            setActiveTab(tab);
          });

          tab.addEventListener("keydown", (event) => {
            let newIndex = -1;

            if (event.key === "ArrowLeft" || event.key === "ArrowUp") {
              newIndex = (tabs.indexOf(tab) - 1 + tabs.length) % tabs.length;
            } else if (
              event.key === "ArrowRight" ||
              event.key === "ArrowDown"
            ) {
              newIndex = (tabs.indexOf(tab) + 1) % tabs.length;
            } else if (event.key === "Home") {
              newIndex = 0;
            } else if (event.key === "End") {
              newIndex = tabs.length - 1;
            }

            if (newIndex === -1) {
              return;
            }

            event.preventDefault();
            tabs[newIndex].focus();
            setActiveTab(tabs[newIndex]);
          });
        });

        const initiallySelected =
          tabs.find((tab) => tab.getAttribute("aria-selected") === "true") ??
          tabs[0];
        setActiveTab(initiallySelected);
      });
    },
  };
})(Drupal, once);
