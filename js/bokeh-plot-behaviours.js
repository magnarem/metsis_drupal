(function (Drupal) {
  /**
   * @namespace
   *
   * Only set if not set by some other metsis component
   */
  if (Drupal.metsis === undefined) {
    Drupal.metsis = {};
  }

  /**
   * Add a bokehPlotForm object with the functions we need.
   */
  Drupal.metsis.bokehPlotForm = {
    /**
     * Periodically check for the Bokeh object. When available,
     * check that the BokehDocument have been rendered and then
     * call the function to reveal the Plot and hide the spinner.-
     */
    revealPlot: function () {
      if (window.Bokeh !== undefined) {
        for (const key in Bokeh.index) {
          if (Object.hasOwn(Bokeh.index, key)) {
            delete Bokeh.index[key]; // Remove the plot or document from the index
            console.log(`Removed Bokeh plot with key: ${key}`);
          }
        }
        Bokeh.documents.forEach((doc, index) => {
          // Clear the document
          if (typeof doc.clear === "function") {
            console.log(`Clearing document at index ${index}`);
            doc.clear({ sync: true });
          }
        });
        Bokeh.documents.length = 0;
        console.log("Bokeh.documents:", Bokeh.documents.length);
      }
      const interval = setInterval(() => {
        if (window.Bokeh !== undefined && Bokeh.documents.length > 0) {
          const doc = Bokeh.documents[0];
          if (doc.is_idle) {
            console.log("Bokeh plot rendered finished. Revealing Plot");
            clearInterval(interval);
            this.hideSpinnerShowPlot();
          }
        }
      }, 200);
    },
    /**
     * Hides the spinner and shows the plot using css classes.
     */
    hideSpinnerShowPlot: function () {
      const spinnerOverlay = document.getElementById(
        "edit-bokeh-spinner-container",
      );
      const bokehPlot = document.getElementById("bokeh-plot");
      if (spinnerOverlay) {
        spinnerOverlay.classList.add("hidden"); // Hide the spinner
      }
      if (bokehPlot) {
        bokehPlot.classList.add("visible"); // Show the plot
      }
    },
    /**
     * Shows the Spinner Hides the plot.
     */
    showSpinnerClearPlot: function () {
      console.log("bokeh:  showSpinnerHidePlot");
      const spinnerOverlay = document.getElementById(
        "edit-bokeh-spinner-container",
      );
      const bokehPlot = document.getElementById("bokeh-plot");
      if (spinnerOverlay) {
        console.log("Bokeh Reveal spinner");
        spinnerOverlay.classList.remove("hidden");
        spinnerOverlay.innerHTML = "";
      }
      if (bokehPlot) {
        bokehPlot.classList.remove("visible"); // Show the plot
      }
    },
    toggleClearButton: function (input) {
      const clearButton = document.querySelector(".opendap-url-clear-button");
      // Check if the input has any characters
      if (
        input.value.trim().length > 0 &&
        !clearButton.classList.contains("visible")
      ) {
        console.log("bokeh: Input has characters. Show clear button.");
        clearButton.classList.add("visible");
      } else if (
        input.value.trim().length === 0 &&
        clearButton.classList.contains("visible")
      ) {
        console.log("bokeh: Input is empty. Hide clear button.");
        clearButton.classList.remove("visible");
      }
    },

    hideClearButton: function () {
      const clearButton = document.querySelector(".opendap-url-clear-button");
      if (clearButton) {
        clearButton.classList.remove("visible");
        console.log("bokeh: Hiding clear button.");
      }
    },

    showClearButton: function () {
      console.log("bokeh: executing showClearButton()");
      const input = document.querySelector(".opendap-url-input");
      if (input) {
        this.toggleClearButton(input); // Recheck the input value to determine visibility
      }
    },

    clearInput: function (inputId) {
      console.log("bokeh:  Clearing input");
      const input = document.getElementById(inputId);
      const clearButton = document.querySelector(".opendap-url-clear-button");
      if (input) {
        input.value = ""; // Clear the input value
        input.focus(); // Return focus to the input
      }
      if (clearButton) {
        clearButton.classList.remove("visible"); // Hide the clear button
      }
      this.clearPlot();
    },
    clearPlot: function () {
      const bokehPlot = document.querySelector("#bokeh-plot");
      if (bokehPlot) {
        bokehPlot.innerHTML = "";
      }
    },
    addLoadingText: function () {
      const desc = document.querySelector("#edit-opendap-url--description");
      if (desc) {
        desc.innerHTML = "<span>Loading...</span>";
      }
    },
  };
})(Drupal);
