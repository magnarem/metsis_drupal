import proj4 from "proj4";
import { register } from "ol/proj/proj4";
import { get as getProjection } from "ol/proj";

// Define UPS North (EPSG:32661)
proj4.defs(
  "EPSG:32661",
  "+proj=stere +lat_0=90 +lon_0=0 +k=0.994 +x_0=2000000 +y_0=2000000 +datum=WGS84 +units=m +no_defs +type=crs",
);

// Define UPS South (EPSG:32761)
proj4.defs(
  "EPSG:32761",
  "+proj=stere +lat_0=-90 +lon_0=0 +k=0.994 +x_0=2000000 +y_0=2000000 +datum=WGS84 +units=m +no_defs +type=crs",
);

//Define EPSG:4326
proj4.defs(
  "EPSG:4326",
  'GEOGCRS["WGS 84",ENSEMBLE["World Geodetic System 1984 ensemble",MEMBER["World Geodetic System 1984 (Transit)",ID["EPSG",1166]],MEMBER["World Geodetic System 1984 (G730)",ID["EPSG",1152]],MEMBER["World Geodetic System 1984 (G873)",ID["EPSG",1153]],MEMBER["World Geodetic System 1984 (G1150)",ID["EPSG",1154]],MEMBER["World Geodetic System 1984 (G1674)",ID["EPSG",1155]],MEMBER["World Geodetic System 1984 (G1762)",ID["EPSG",1156]],MEMBER["World Geodetic System 1984 (G2139)",ID["EPSG",1309]],MEMBER["World Geodetic System 1984 (G2296)",ID["EPSG",1383]],ELLIPSOID["WGS 84",6378137,298.257223563,LENGTHUNIT["metre",1,ID["EPSG",9001]],ID["EPSG",7030]],ENSEMBLEACCURACY[2],ID["EPSG",6326]],CS[ellipsoidal,2,ID["EPSG",6422]],AXIS["Geodetic latitude (Lat)",north],AXIS["Geodetic longitude (Lon)",east],ANGLEUNIT["degree",0.0174532925199433,ID["EPSG",9102]],ID["EPSG",4326]]',
);

// Define EPSG:3574
proj4.defs("EPSG:3574","+proj=laea +lat_0=90 +lon_0=-40 +x_0=0 +y_0=0 +datum=WGS84 +units=m +no_defs +type=crs");
// Register proj4 with OpenLayers
register(proj4);

// Now get and set extent, but only if projection is available
const proj32661 = getProjection("EPSG:32661");
if (proj32661) {
  proj32661.setExtent([-6e6, -3e6, 9e6, 6e6]);
}

const proj32761 = getProjection("EPSG:32761");
if (proj32761) {
  proj32761.setExtent([-8e6, -8e6, 12e6, 10e6]);
}

const proj3574 = getProjection("EPSG:3574");
if (proj3574) {
  proj3574.setExtent([-4e6, -4e6, 4e6, 4e6]);
}
