import { __ } from "@wordpress/i18n";

export const formatMs = (value?: number) =>
  value == null ? "—" : `${value.toFixed(2)} ms`;

export const errorText = (error: unknown) =>
  error instanceof Error
    ? error.message
    : (error as { message?: string })?.message ||
      __("The operation failed. Please try again.", "request-inspector");
