import { useEffect, useState } from "preact/hooks";
import { get as getProjection, transform, getPointResolution } from "ol/proj";
import View from "ol/View";

const ProjectionSwitcher = ({
  mapInstance,
  supportedProjections,
  defaultProjection,
  setProjection,
}) => {
  const [selectedProjection, setSelectedProjection] =
    useState(defaultProjection);

  // Update the map's projection whenever the selected projection changes
  useEffect(() => {
    if (!mapInstance) return;

    console.log("Selected projection:", selectedProjection);
    // Get the current center and zoom of the map
    const currentView = mapInstance.getView();
    const currentProjection = currentView.getProjection();
    const newProjection = getProjection(selectedProjection);
    const currentResolution = currentView.getResolution();
    const currentCenter = currentView.getCenter();
    const currentRotation = currentView.getRotation();
    const newCenter = transform(
      currentCenter,
      currentProjection,
      newProjection,
    );
    const currentMPU = currentProjection.getMetersPerUnit();
    const newMPU = newProjection.getMetersPerUnit();
    const currentPointResolution =
      getPointResolution(
        currentProjection,
        1 / currentMPU,
        currentCenter,
        "m",
      ) * currentMPU;
    const newPointResolution =
      getPointResolution(newProjection, 1 / newMPU, newCenter, "m") * newMPU;
    const newResolution =
      (currentResolution * currentPointResolution) / newPointResolution;
    const newView = new View({
      center: newCenter,
      resolution: newResolution,
      rotation: currentRotation,
      projection: newProjection,
    });
    mapInstance.setView(newView);

    if (setProjection) {
      setProjection(selectedProjection); // Notify parent
    }
  }, [selectedProjection, mapInstance]); // Dependency array ensures this runs when selectedProjection or mapInstance changes

  // Handle user selection of a new projection
  const handleProjectionChange = (event) => {
    event.preventDefault();
    setSelectedProjection(event.target.value); // Update the selected projection state
  };

  return (
    <div className="projection-switcher">
      <label htmlFor="projection-select" style={{ marginRight: "10px" }}>
        Projection:
      </label>
      <select
        id="projection-select"
        value={selectedProjection}
        onChange={handleProjectionChange}
        style={{ padding: "5px", fontSize: "14px" }}
      >
        {Object.entries(supportedProjections).map(([code, name]) => (
          <option key={code} value={code}>
            {name} ({code})
          </option>
        ))}
      </select>
    </div>
  );
};

export default ProjectionSwitcher;
