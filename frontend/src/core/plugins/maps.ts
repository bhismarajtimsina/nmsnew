import app from '../../config/configApp';
import { LMap, LTileLayer, LMarker, LControlLayers, LPolyline, LPopup, LTooltip, LLayerGroup } from '@vue-leaflet/vue-leaflet';
import 'leaflet/dist/leaflet.css';

app.component('l-map', LMap);
app.component('l-tile-layer', LTileLayer);
app.component('l-marker', LMarker);
app.component('l-control-layer', LControlLayers);
// Added for the Map page (Links & Topology > Map): device/ONT markers grouped
// into toggleable layers, link lines between devices, and popups/tooltips on
// hover/click — none of which the original scaffold registered.
app.component('l-polyline', LPolyline);
app.component('l-popup', LPopup);
app.component('l-tooltip', LTooltip);
app.component('l-layer-group', LLayerGroup);
