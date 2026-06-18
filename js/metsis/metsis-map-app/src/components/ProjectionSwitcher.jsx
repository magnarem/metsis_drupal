import { useEffect, useState } from "preact/hooks";
import { switchMapViewProjection } from "@utils/mapProjection";

const ProjectionSwitcher = ({
  mapInstance,
  supportedProjections,
  defaultProjection,
  setProjection,
}) => {
  const [selectedProjection, setSelectedProjection] =
    useState(defaultProjection);

  useEffect(() => {
    if (defaultProjection && defaultProjection !== selectedProjection) {
      setSelectedProjection(defaultProjection);
    }
  }, [defaultProjection]);

  // Update the map's projection whenever the selected projection changes
  useEffect(() => {
    if (!mapInstance) return;

    console.log("Selected projection:", selectedProjection);
    switchMapViewProjection(mapInstance, selectedProjection);

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
