import React from "react";
import ReactDOM from "react-dom/client";
import { LicenseAdminCard } from "./components/LicenseAdminCard";
import initWasm from "./wasm/litespace_core.js";

declare const LiteSpaceConfig: {
  productSlug: string;
  productName: string;
  publicKey: string;
  apiUrl: string;
  wasmUrl: string;
  initialLicenseData: any;
  nonce: string;
  ajaxUrl?: string;
};

export const AdminApp: React.FC = () => {
  return (
    <div className="litespace-admin-wrapper max-w-4xl py-2">
      <LicenseAdminCard
        productSlug={LiteSpaceConfig.productSlug}
        productName={LiteSpaceConfig.productName}
        publicKey={LiteSpaceConfig.publicKey}
        apiUrl={LiteSpaceConfig.apiUrl}
        initialLicenseData={LiteSpaceConfig.initialLicenseData}
        onActivated={async (result: any) => {
          console.log("[LiteSpace] Lisensi berhasil diaktivasi via Wasm:", result);
          try {
            const ajaxUrl =
              (typeof window !== "undefined" && (window as any).ajaxurl) ||
              LiteSpaceConfig.ajaxUrl ||
              "/wp-admin/admin-ajax.php";

            const inputElem = document.getElementById("licenseKey") as HTMLInputElement | null;
            const licenseKey = result.licenseKey || (inputElem ? inputElem.value.trim() : "");

            const formData = new FormData();
            formData.append("action", "litespace_sync_license");
            formData.append("nonce", LiteSpaceConfig.nonce);
            formData.append("license_key", licenseKey);
            formData.append("certificate_json", result.certificateJson || "");
            formData.append("signature", result.signature || "");
            formData.append("public_key", result.publicKey || LiteSpaceConfig.publicKey);

            await fetch(ajaxUrl, {
              method: "POST",
              body: formData,
              credentials: "same-origin",
            });
          } catch (e) {
            console.error("[LiteSpace] Error syncing license with WordPress:", e);
          }
          window.location.reload();
        }}
        onDeactivated={async () => {
          console.log("[LiteSpace] Lisensi dilepas.");
          try {
            const ajaxUrl =
              (typeof window !== "undefined" && (window as any).ajaxurl) ||
              LiteSpaceConfig.ajaxUrl ||
              "/wp-admin/admin-ajax.php";

            const formData = new FormData();
            formData.append("action", "litespace_deactivate_license");
            formData.append("nonce", LiteSpaceConfig.nonce);

            await fetch(ajaxUrl, {
              method: "POST",
              body: formData,
              credentials: "same-origin",
            });
          } catch (e) {
            console.error("[LiteSpace] Error deactivating in WordPress:", e);
          }
          window.location.reload();
        }}
        onCompiled={async (artifacts: any) => {
          console.log("[LiteSpace] Kompilasi Build-on-Save selesai:", artifacts);
          try {
            const ajaxUrl =
              (typeof window !== "undefined" && (window as any).ajaxurl) ||
              LiteSpaceConfig.ajaxUrl ||
              "/wp-admin/admin-ajax.php";

            const formData = new FormData();
            formData.append("action", "litespace_save_compiled_css");
            formData.append("nonce", LiteSpaceConfig.nonce);
            formData.append("artifacts", JSON.stringify(artifacts));

            await fetch(ajaxUrl, {
              method: "POST",
              body: formData,
              credentials: "same-origin",
            });
          } catch (e) {
            console.error("[LiteSpace] Error syncing compiled CSS with WordPress:", e);
          }
        }}
      />
    </div>
  );
};

function mountIsland() {
  const rootElement = document.getElementById("litespace-license-root");
  if (rootElement && typeof LiteSpaceConfig !== "undefined") {
    const wasmUrl = LiteSpaceConfig.wasmUrl;
    initWasm(wasmUrl)
      .then(() => {
        const root = ReactDOM.createRoot(rootElement);
        root.render(<AdminApp />);
      })
      .catch((err) => {
        console.warn("[LiteSpace] Init error, rendering with fallback:", err);
        const root = ReactDOM.createRoot(rootElement);
        root.render(<AdminApp />);
      });
  }
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", mountIsland);
} else {
  mountIsland();
}
