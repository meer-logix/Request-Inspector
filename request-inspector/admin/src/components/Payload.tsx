import React, { useState } from "@wordpress/element";
import { __, sprintf } from "@wordpress/i18n";
import type { Body } from "../types";
import { Notice } from "./Notice";

export function Payload({ body }: { body?: Body }) {
  const [pretty, setPretty] = useState(true);
  const [copied, setCopied] = useState("");
  
  if (!body) {
    return <Notice>{__("No body was captured.", "request-inspector")}</Notice>;
  }
  
  let content = body.content;
  if (content && !pretty) {
    try {
      content = JSON.stringify(JSON.parse(content));
    } catch {
      /* Truncated JSON remains text. */
    }
  }
  
  return (
    <section>
      <div className="ri-section-head">
        <h3>{body.content_type || __("Body", "request-inspector")}</h3>
        <div className="ri-actions">
          <button aria-pressed={pretty} onClick={() => setPretty(!pretty)}>
            {pretty
              ? __("Pretty", "request-inspector")
              : __("Raw", "request-inspector")}
          </button>
          <button
            disabled={!content}
            onClick={async () => {
              try {
                await navigator.clipboard.writeText(content || "");
                setCopied(__("Copied sanitized content.", "request-inspector"));
              } catch {
                setCopied(
                  __(
                    "Clipboard unavailable. Select the text to copy.",
                    "request-inspector",
                  ),
                );
              }
            }}
          >
            {__("Copy", "request-inspector")}
          </button>
        </div>
      </div>
      <p role="status">{copied}</p>
      {body.reason && (
        <Notice>
          {sprintf(__("Capture note: %s", "request-inspector"), body.reason)}
        </Notice>
      )}
      {content !== null && (
        <pre
          tabIndex={0}
          aria-label={__("Sanitized payload", "request-inspector")}
        >
          {content}
        </pre>
      )}
      <p className="ri-muted">
        {sprintf(
          __(
            "%d bytes stored · credentials are redacted before storage",
            "request-inspector",
          ),
          body.stored_size,
        )}
      </p>
    </section>
  );
}
