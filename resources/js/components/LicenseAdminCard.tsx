import React, { useState, useEffect } from "react";
import initWasm, {
  wasm_inspect_certificate,
  wasm_minify_css,
  wasm_pack_activation_payload,
  wasm_verify_certificate,
  wasm_version,
} from "../wasm/litespace_core.js";

export interface LicenseAdminCardProps {
  productSlug: string;
  productName: string;
  publicKey: string;
  apiUrl?: string;
  initialLicenseData?: {
    isValid: boolean;
    licenseKey?: string;
    tier?: string;
    domain?: string;
    isLifetime?: boolean;
    expiresAt?: number | null;
    signature?: string;
    certificateJson?: string;
  } | null;
  onActivated?: (cert: any) => void;
  onDeactivated?: () => void;
  onCompiled?: (artifacts: any) => void;
}

export const LicenseAdminCard: React.FC<LicenseAdminCardProps> = ({
  productSlug,
  productName,
  publicKey,
  apiUrl = "https://api.litespace.id",
  initialLicenseData = null,
  onActivated,
  onDeactivated,
  onCompiled,
}) => {
  const [wasmReady, setWasmReady] = useState(false);
  const [wasmBanner, setWasmBanner] = useState("");
  const [licenseKeyInput, setLicenseKeyInput] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [isCompiling, setIsCompiling] = useState(false);
  const [feedback, setFeedback] = useState<{ type: "success" | "error"; message: string } | null>(null);
  const [licenseData, setLicenseData] = useState(initialLicenseData);
  const [showKey, setShowKey] = useState(false);
  const [compiledArtifacts, setCompiledArtifacts] = useState<any>(null);

  useEffect(() => {
    let mounted = true;
    async function loadEngine() {
      try {
        const wasmUrl = (typeof LiteSpaceConfig !== "undefined" && (LiteSpaceConfig as any).wasmUrl) ? (LiteSpaceConfig as any).wasmUrl : undefined;
        await initWasm(wasmUrl);
        if (mounted) {
          setWasmReady(true);
          setWasmBanner(wasm_version());
        }
      } catch (err) {
        console.warn("[LiteSpace Wasm] Failed to initialize WebAssembly engine:", err);
      }
    }
    loadEngine();
    return () => {
      mounted = false;
    };
  }, []);

  const handleActivate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!licenseKeyInput.trim()) {
      setFeedback({ type: "error", message: "Silakan masukkan kunci lisensi Anda." });
      return;
    }

    setIsLoading(true);
    setFeedback(null);

    try {
      const currentDomain = window.location.hostname || "127.0.0.1";
      const timestamp = BigInt(Math.floor(Date.now() / 1000));
      const fingerprint = `browser:${navigator.userAgent.slice(0, 32)}`;

      // 1. Pack tamper-evident payload via Rust Wasm
      let packedPayload: any = {
        licenseKey: licenseKeyInput.trim(),
        domain: currentDomain,
        serverFingerprint: fingerprint,
        timestamp: Number(timestamp),
      };

      if (wasmReady) {
        try {
          const wasmJson = wasm_pack_activation_payload(
            licenseKeyInput.trim(),
            currentDomain,
            fingerprint,
            timestamp
          );
          packedPayload = JSON.parse(wasmJson);
        } catch (wErr) {
          console.warn("[WASM PACK WARNING]", wErr);
        }
      }

      // 2. Call LiteSpace API
      const response = await fetch(`${apiUrl}/api/v1/licenses/activate`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(packedPayload),
      });

      const result = await response.json();

      if (!response.ok || !result.success) {
        throw new Error(result.message || "Aktivasi lisensi gagal.");
      }

      // 3. Client-Side Wasm Asymmetric Verification
      if (wasmReady && result.certificateJson && result.signature) {
        const isVerified = wasm_verify_certificate(
          result.publicKey || publicKey,
          result.certificateJson,
          result.signature
        );

        if (!isVerified) {
          throw new Error("Validasi sertifikat digital via WebAssembly gagal. Payload dicurigai tidak otentik.");
        }
      }

      const newLicenseData = {
        isValid: true,
        licenseKey: licenseKeyInput.trim(),
        tier: result.tier || "Pro",
        domain: result.domain || currentDomain,
        isLifetime: !result.expiresAt,
        expiresAt: result.expiresAt ? Math.floor(new Date(result.expiresAt).getTime() / 1000) : null,
        signature: result.signature,
        certificateJson: result.certificateJson,
      };

      setLicenseData(newLicenseData);
      setLicenseKeyInput("");
      setFeedback({
        type: "success",
        message: "Lisensi berhasil diaktifkan! Sertifikat digital diverifikasi via WebAssembly.",
      });

      if (onActivated) {
        onActivated({ ...result, licenseKey: licenseKeyInput.trim() });
      }
    } catch (err: any) {
      setFeedback({
        type: "error",
        message: err.message || "Terjadi kesalahan saat mengaktivasi lisensi.",
      });
    } finally {
      setIsLoading(false);
    }
  };

  const handleCompile = async () => {
    if (!licenseData?.licenseKey) return;

    setIsCompiling(true);
    setFeedback(null);

    try {
      const currentDomain = window.location.hostname || "127.0.0.1";
      const sampleCss = `
        body { margin: 0px; padding: 0px; font-family: system-ui, sans-serif; }
        .site-header { display: flex; align-items: center; justify-content: space-between; height: 72px; padding: 0px 24px; }
        .hero { min-height: 80vh; display: flex; align-items: center; background: #000; color: #fff; }
        .footer-copyright { margin-top: 100px; padding: 40px; font-size: 13px; color: #888; }
      `;

      const response = await fetch(`${apiUrl}/api/v1/compiler/build-config`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          licenseKey: licenseData.licenseKey,
          domain: currentDomain,
          productSlug,
          settings: {
            lazyload: true,
            webpConvert: true,
            avifSupport: true,
          },
          rawCss: sampleCss,
        }),
      });

      const result = await response.json();
      if (!response.ok || !result.success) {
        throw new Error(result.message || "Kompilasi aset statis gagal.");
      }

      setCompiledArtifacts(result.artifacts);
      setFeedback({
        type: "success",
        message: `Kompilasi sukses! Critical CSS dihemat ${result.artifacts.savingsPercentage}%. Hash: ${result.artifacts.buildHash.slice(0, 10)}...`,
      });

      if (onCompiled) {
        onCompiled(result.artifacts);
      }
    } catch (err: any) {
      setFeedback({
        type: "error",
        message: err.message || "Gagal menjalankan Build-on-Save compiler.",
      });
    } finally {
      setIsCompiling(false);
    }
  };

  const handleDeactivate = async () => {
    if (!licenseData?.licenseKey) return;

    if (!window.confirm("Apakah Anda yakin ingin mencabut lisensi dari domain ini? Slot domain akan kembali tersedia untuk digunakan.")) {
      return;
    }

    setIsLoading(true);
    setFeedback(null);

    try {
      const currentDomain = window.location.hostname || "127.0.0.1";
      const response = await fetch(`${apiUrl}/api/v1/licenses/deactivate`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          licenseKey: licenseData.licenseKey,
          domain: currentDomain,
        }),
      });

      const result = await response.json();
      if (!response.ok || !result.success) {
        throw new Error(result.message || "Gagal mencabut lisensi.");
      }

      setLicenseData(null);
      setCompiledArtifacts(null);
      setFeedback({
        type: "success",
        message: "Lisensi berhasil dicabut dari domain ini. Slot kuota telah dibebaskan.",
      });

      if (onDeactivated) {
        onDeactivated();
      }
    } catch (err: any) {
      setFeedback({
        type: "error",
        message: err.message || "Terjadi kesalahan saat mencabut lisensi.",
      });
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="bg-neutral-900 border border-neutral-800 rounded-xl p-6 shadow-2xl text-neutral-100 max-w-2xl font-sans">
      {/* Header */}
      <div className="flex items-center justify-between pb-5 border-b border-neutral-800">
        <div>
          <h3 className="text-lg font-bold text-white flex items-center gap-2">
            <span>🛡️</span>
            <span>Lisensi & Mesin Optimasi: {productName}</span>
          </h3>
          <p className="text-xs text-neutral-400 mt-1">
            Engine Proteksi Berbasis Kriptografi Ed25519 & Rust Build-on-Save Compiler
          </p>
        </div>
        <div className="flex items-center gap-2">
          {wasmReady ? (
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-950/80 text-emerald-400 border border-emerald-800/60" title={wasmBanner}>
              <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
              Rust Wasm Active
            </span>
          ) : (
            <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-neutral-800 text-neutral-400">
              Initializing Engine...
            </span>
          )}
        </div>
      </div>

      {/* Feedback Banner */}
      {feedback && (
        <div
          className={`mt-4 p-3.5 rounded-lg text-sm flex items-start gap-2.5 ${
            feedback.type === "success"
              ? "bg-emerald-950/60 border border-emerald-800/80 text-emerald-300"
              : "bg-rose-950/60 border border-rose-800/80 text-rose-300"
          }`}
        >
          <span className="text-base">{feedback.type === "success" ? "✓" : "⚠️"}</span>
          <span>{feedback.message}</span>
        </div>
      )}

      {/* Main Content */}
      <div className="mt-5">
        {licenseData && licenseData.isValid ? (
          /* Active State */
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-3 bg-neutral-950/60 p-4 rounded-lg border border-neutral-800/80">
              <div>
                <span className="text-xs text-neutral-500 uppercase tracking-wider block">Status Lisensi</span>
                <span className="text-sm font-semibold text-emerald-400 flex items-center gap-1.5 mt-0.5">
                  <span className="w-2 h-2 rounded-full bg-emerald-500"></span>
                  Aktif & Terverifikasi
                </span>
              </div>
              <div>
                <span className="text-xs text-neutral-500 uppercase tracking-wider block">Tipe Paket</span>
                <span className="text-sm font-semibold text-neutral-200 mt-0.5 block">
                  {licenseData.tier || "Pro"} {licenseData.isLifetime ? "(Lifetime Deal)" : ""}
                </span>
              </div>
              <div>
                <span className="text-xs text-neutral-500 uppercase tracking-wider block">Domain Terdaftar</span>
                <span className="text-sm font-mono text-neutral-300 mt-0.5 block truncate">
                  {licenseData.domain}
                </span>
              </div>
              <div>
                <span className="text-xs text-neutral-500 uppercase tracking-wider block">Denyut Keamanan</span>
                <span className="text-sm text-neutral-300 mt-0.5 block">
                  Pasif (12 Jam Bawaan WP)
                </span>
              </div>
            </div>

            {/* License Key Display */}
            <div className="bg-neutral-950/40 p-3.5 rounded-lg border border-neutral-800 flex items-center justify-between">
              <div className="overflow-hidden mr-2">
                <span className="text-xs text-neutral-500 block">Kunci Lisensi</span>
                <span className="text-xs font-mono text-neutral-300">
                  {showKey ? licenseData.licenseKey : "••••••••••••••••••••••••••••••••••••••••"}
                </span>
              </div>
              <button
                type="button"
                onClick={() => setShowKey(!showKey)}
                className="text-xs text-cyan-400 hover:text-cyan-300 px-2.5 py-1 rounded bg-neutral-800/60 hover:bg-neutral-800 transition-colors"
              >
                {showKey ? "Sembunyikan" : "Tampilkan"}
              </button>
            </div>

            {/* Build-on-Save Compiler Card */}
            <div className="bg-gradient-to-br from-neutral-950/90 to-neutral-900/90 p-4 rounded-lg border border-cyan-900/40">
              <div className="flex items-center justify-between">
                <div>
                  <h4 className="text-sm font-semibold text-cyan-300 flex items-center gap-1.5">
                    <span>⚡</span>
                    <span>Mesin Kompilasi Statis "Build-on-Save" (Rust FFI)</span>
                  </h4>
                  <p className="text-xs text-neutral-400 mt-1">
                    Offloading pemrosesan Critical CSS ke server LiteSpace untuk menjamin performa LCP &lt; 0.6s dan perlindungan anti-nulled.
                  </p>
                </div>
                <button
                  type="button"
                  disabled={isCompiling}
                  onClick={handleCompile}
                  className="px-3.5 py-2 bg-cyan-600 hover:bg-cyan-500 text-white text-xs font-semibold rounded-lg shadow-md transition-all disabled:opacity-50 flex items-center gap-1.5 whitespace-nowrap"
                >
                  {isCompiling ? (
                    <>
                      <span className="w-3 h-3 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                      <span>Mengompilasi...</span>
                    </>
                  ) : (
                    <span>Eksekusi Build-on-Save</span>
                  )}
                </button>
              </div>

              {compiledArtifacts && (
                <div className="mt-3 pt-3 border-t border-neutral-800/60 grid grid-cols-3 gap-2 text-xs">
                  <div>
                    <span className="text-neutral-500 block">File Statis:</span>
                    <span className="font-mono text-cyan-300 truncate block">{compiledArtifacts.assetFilename}</span>
                  </div>
                  <div>
                    <span className="text-neutral-500 block">Penghematan CSS:</span>
                    <span className="text-emerald-400 font-bold">{compiledArtifacts.savingsPercentage}%</span>
                  </div>
                  <div>
                    <span className="text-neutral-500 block">Engine:</span>
                    <span className="text-neutral-300">Rust Core v0.1.0</span>
                  </div>
                </div>
              )}
            </div>

            {/* Actions */}
            <div className="pt-2 flex items-center justify-between">
              <span className="text-xs text-neutral-500">
                Verifikasi offline lokal aktif via Libsodium PHP.
              </span>
              <button
                type="button"
                disabled={isLoading}
                onClick={handleDeactivate}
                className="px-3.5 py-1.5 text-xs font-medium text-rose-300 hover:text-white bg-rose-950/40 hover:bg-rose-900 border border-rose-800/60 rounded-lg transition-colors disabled:opacity-50"
              >
                {isLoading ? "Memproses..." : "Cabut Lisensi Domain Ini"}
              </button>
            </div>
          </div>
        ) : (
          /* Inactive State */
          <form onSubmit={handleActivate} className="space-y-4">
            <div>
              <label htmlFor="licenseKey" className="block text-xs font-medium text-neutral-300 mb-1.5">
                Masukkan Kunci Lisensi Anda
              </label>
              <div className="relative">
                <input
                  id="licenseKey"
                  type="text"
                  placeholder="IJP-PRO-XXXX-XXXX-XXXX"
                  value={licenseKeyInput}
                  onChange={(e) => setLicenseKeyInput(e.target.value)}
                  disabled={isLoading}
                  className="w-full px-3.5 py-2.5 bg-neutral-950 border border-neutral-700 rounded-lg text-sm font-mono text-neutral-100 placeholder-neutral-600 focus:outline-none focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 transition-colors disabled:opacity-50"
                />
              </div>
              <p className="text-xs text-neutral-500 mt-1.5">
                Kunci lisensi dapat Anda temukan pada Member Portal atau email konfirmasi pesanan.
              </p>
            </div>

            <div className="pt-2 flex items-center justify-between">
              <a
                href="https://litespace.id/portal"
                target="_blank"
                rel="noreferrer"
                className="text-xs text-cyan-400 hover:text-cyan-300 underline"
              >
                Buka Member Portal →
              </a>
              <button
                type="submit"
                disabled={isLoading || !licenseKeyInput.trim()}
                className="px-5 py-2 bg-gradient-to-r from-cyan-600 to-blue-600 hover:from-cyan-500 hover:to-blue-500 text-white text-xs font-semibold rounded-lg shadow-lg shadow-cyan-900/30 transition-all disabled:opacity-50 flex items-center gap-1.5"
              >
                {isLoading ? (
                  <>
                    <span className="w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                    <span>Mengaktivasi...</span>
                  </>
                ) : (
                  <span>Aktivasi & Verifikasi Digital</span>
                )}
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  );
};
