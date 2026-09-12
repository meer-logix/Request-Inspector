import React from "react";
import { __ } from "@wordpress/i18n";
import type { Caller } from "../types";
import { Notice } from "./Notice";
import { KeyValues } from "./KeyValues";

export function Trace({ caller }: { caller?: Caller }) {
  return caller ? (
    <div>
      <KeyValues
        values={{
          [__("Component", "request-inspector")]: caller.component,
          [__("File", "request-inspector")]: caller.file,
          [__("Line", "request-inspector")]: caller.line,
          [__("Function", "request-inspector")]: caller.function,
        }}
      />
      {!!caller.trace?.length && (
        <details>
          <summary>{__("Caller backtrace", "request-inspector")}</summary>
          <pre>
            {caller.trace
              .map(
                (frame, i) =>
                  `${i + 1}. ${frame.function || ""}\n   ${frame.file}:${
                    frame.line
                  }`,
              )
              .join("\n")}
          </pre>
        </details>
      )}
    </div>
  ) : (
    <Notice>
      {__(
        "Caller information is unavailable for this capture.",
        "request-inspector",
      )}
    </Notice>
  );
}
