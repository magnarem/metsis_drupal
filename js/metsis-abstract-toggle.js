/**
 * @file
 * Handles expand/collapse behavior for long abstract snippets in result rows.
 */

(function (Drupal, once) {
  "use strict";

  Drupal.behaviors.metsisAbstractToggle = {
    attach(context) {
      once(
        "metsis-abstract-toggle",
        ".metsis-abstract-wrapper",
        context,
      ).forEach((wrapper) => {
        const quote = wrapper.querySelector(".metsis-search-quote");
        const button = wrapper.querySelector(".metsis-abstract-toggle");
        const buttonText = wrapper.querySelector(
          ".metsis-abstract-toggle-text",
        );

        if (!quote || !button) {
          return;
        }

        const collapseLabel = Drupal.t("Expand abstract");
        const expandLabel = Drupal.t("Collapse abstract");
        const collapsedHeight = quote.getBoundingClientRect().height;

        const animateHeight = (from, to, onDone) => {
          quote.style.height = `${from}px`;
          // Force reflow so the browser picks up the start height.
          void quote.offsetHeight;
          quote.style.height = `${to}px`;

          const onTransitionEnd = (event) => {
            if (event.propertyName !== "height") {
              return;
            }
            quote.removeEventListener("transitionend", onTransitionEnd);
            if (typeof onDone === "function") {
              onDone();
            }
          };

          quote.addEventListener("transitionend", onTransitionEnd);
        };

        const setExpanded = (expanded) => {
          wrapper.classList.toggle("is-expanded", expanded);
          button.setAttribute("aria-expanded", expanded ? "true" : "false");
          const label = expanded ? expandLabel : collapseLabel;
          button.setAttribute("aria-label", label);
          if (buttonText) {
            buttonText.textContent = label;
          }
        };

        const updateOverflowState = () => {
          const isExpanded = wrapper.classList.contains("is-expanded");
          if (isExpanded) {
            quote.style.height = "auto";
            return;
          }

          quote.style.height = `${collapsedHeight}px`;
          const hasOverflow = quote.scrollHeight > quote.clientHeight + 1;
          wrapper.classList.toggle("is-not-overflowing", !hasOverflow);

          if (!hasOverflow) {
            quote.style.height = "auto";
          }
        };

        setExpanded(false);
        updateOverflowState();

        button.addEventListener("click", () => {
          if (wrapper.classList.contains("is-not-overflowing")) {
            return;
          }

          const expanded = button.getAttribute("aria-expanded") === "true";
          if (!expanded) {
            const from = quote.getBoundingClientRect().height;
            setExpanded(true);
            const to = quote.scrollHeight;
            animateHeight(from, to, () => {
              if (wrapper.classList.contains("is-expanded")) {
                quote.style.height = "auto";
              }
            });
            return;
          }

          const from = quote.getBoundingClientRect().height;
          setExpanded(false);
          animateHeight(from, collapsedHeight);
        });

        window.addEventListener("resize", updateOverflowState, {
          passive: true,
        });
      });
    },
  };
})(Drupal, once);
