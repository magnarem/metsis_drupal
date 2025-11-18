import { useEffect } from "preact/hooks";

const TooltipHover = ({ mapInstance }) => {
  useEffect(() => {
    if (!mapInstance) return;
    // Create a tooltip element
    const tooltipElement = document.createElement("div");
    tooltipElement.id = "#info";
    tooltipElement.className = ".ol-control";
    tooltipElement.style.position = "absolute";
    tooltipElement.style.visibility = "hidden";
    tooltipElement.style.pointerEvents = "none";
    tooltipElement.style.background = "rgba(0, 0, 0, 0.7)";
    tooltipElement.style.color = "#fff";
    tooltipElement.style.padding = "4px 8px";
    tooltipElement.style.borderRadius = "4px";
    tooltipElement.style.whiteSpace = "nowrap";
    tooltipElement.style.fontSize = "12px";
    tooltipElement.style.zIndex = "1000";

    // Append the tooltip to the map container
    const mapContainer = mapInstance.getTargetElement();
    mapContainer.appendChild(tooltipElement);

    let currentFeature;

    // Function to display feature information
    const displayFeatureInfo = (pixel, target) => {
      // Check if the target is a control (like zoom buttons) and ignore
      const feature = target.closest(".ol-control")
        ? undefined
        : mapInstance.forEachFeatureAtPixel(pixel, (feature) => feature);

      if (feature) {
        const title = feature.get("title"); // Get the 'title' property
        if (title) {
          tooltipElement.style.left = pixel[0] + "px";
          tooltipElement.style.top = pixel[1] + "px";
          if (feature !== currentFeature) {
            tooltipElement.style.visibility = "visible";
            tooltipElement.innerText = title;
          }
        }
      } else {
        tooltipElement.style.visibility = "hidden";
        tooltipElement.innerText = "";
      }
      currentFeature = feature;
    };

    // Add pointermove event listener to the map
    mapInstance.on("pointermove", (evt) => {
      if (evt.dragging) {
        tooltipElement.style.visibility = "hidden";
        tooltipElement.innerText = "";
        currentFeature = undefined;
        return;
      }
      displayFeatureInfo(evt.pixel, evt.originalEvent.target);
    });

    // Add click event listener to display feature info
    mapInstance.on("click", (evt) => {
      displayFeatureInfo(evt.pixel, evt.originalEvent.target);
    });

    // Hide tooltip when the mouse leaves the map
    mapContainer.addEventListener("pointerleave", () => {
      currentFeature = undefined;
      tooltipElement.style.visibility = "hidden";
      tooltipElement.innerText = "";
    });

    // Cleanup on component unmount
    return () => {
      mapContainer.removeChild(tooltipElement);
      mapInstance.un("pointermove");
      mapInstance.un("click");
    };
  }, [mapInstance]);

  return null; // This component adds functionality to the map but doesn't render anything itself
};

export default TooltipHover;
