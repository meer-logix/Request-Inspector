export const urlState = () => new URLSearchParams(window.location.search);

export const initialView = () => {
  const selected = urlState().get("ri_view");
  if (
    selected &&
    [
      "explorer",
      "settings",
      "database",
      "php",
      "hooks",
      "advanced",
      "workflows",
    ].includes(selected)
  )
    return selected;
  const page = urlState().get("page") || "";
  return page.endsWith("-settings")
    ? "settings"
    : page.endsWith("-database")
    ? "database"
    : page.endsWith("-php")
    ? "php"
    : "explorer";
};
