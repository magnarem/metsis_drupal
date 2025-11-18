const LayerSwitcher = ({ mapInstance }) => {
  const toggleLayerVisibility = (layer) => {
    layer.setVisible(!layer.getVisible());
  };

  return (
    <div className="layer-switcher">
      {mapInstance &&
        mapInstance
          .getLayers()
          .getArray()
          .map((layer, index) => (
            <div key={index}>
              <button onClick={() => toggleLayerVisibility(layer)}>
                {layer.getVisible() ? "Hide" : "Show"} Layer {index + 1}
              </button>
            </div>
          ))}
    </div>
  );
};

export default LayerSwitcher;
