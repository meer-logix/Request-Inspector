// Use the same local image URL before and after React mounts so the browser
// downloads the supplied artwork once and reuses its cache throughout the app.
export const logo = document.getElementById("request-inspector-app")?.dataset.logo || "";
