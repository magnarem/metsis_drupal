import { useEffect, useRef, useState } from "preact/hooks";
import { switchMapViewProjection } from "@utils/mapProjection";

const ProjectionSwitcher = ({
  mapInstance,
  supportedProjections,
  defaultProjection,
  setProjection,
}) => {
  const [selectedProjection, setSelectedProjection] =
    useState(defaultProjection);
  const userTriggeredChangeRef = useRef(false);

  useEffect(() => {
    if (defaultProjection && defaultProjection !== selectedProjection) {
      console.info(
        "[METSIS/ProjectionSwitcher] Syncing projection from parent",
        {
          defaultProjection,
        },
      );
      setSelectedProjection(defaultProjection);
    }
  }, [defaultProjection, selectedProjection]);

  // Update the map's projection whenever the selected projection changes
  useEffect(() => {
    if (!mapInstance) return;

    const currentProjectionCode = mapInstance
      .getView()
      ?.getProjection()
      ?.getCode();

    if (currentProjectionCode === selectedProjection) {
      if (setProjection) {
        setProjection(selectedProjection);
      }
      userTriggeredChangeRef.current = false;
      return;
    }

    if (!userTriggeredChangeRef.current) {
      console.info(
        "[METSIS/ProjectionSwitcher] Ignoring non-user projection update",
        {
          selectedProjection,
          currentProjectionCode,
        },
      );
      return;
    }

    console.info(
      "[METSIS/ProjectionSwitcher] Applying user-selected projection",
      {
        from: currentProjectionCode,
        to: selectedProjection,
      },
    );

    switchMapViewProjection(mapInstance, selectedProjection);

    if (setProjection) {
      setProjection(selectedProjection); // Notify parent
    }

    userTriggeredChangeRef.current = false;
  }, [selectedProjection, mapInstance, setProjection]);

  // Handle user selection of a new projection
  const handleProjectionChange = (event) => {
    event.preventDefault();
    userTriggeredChangeRef.current = true;
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
