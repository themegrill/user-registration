import { DeliveryOutcome, ScanResult } from "../api/healthCheckupApi";
import { WizardStep } from "../components/Stepper";

export interface PersistedState {
	step: WizardStep;
	/** Both scan steps read from this. */
	scan: ScanResult | null;
	deliveryOutcome: DeliveryOutcome | null;
	testEmailSent: boolean;
	/** Optional, so a payload saved before this existed still restores. */
	sendError?: string | null;
}

// Bump the suffix to retire state saved by an older shape. v3 split the scan
// step in two; v4 moved SPF and DMARC out of the delivery section.
const STORAGE_KEY = "urEmailHealthCheckup:v4";

const VALID_STEPS: WizardStep[] = [
	"intro",
	"settings",
	"delivery",
	"test",
	"result-good",
	"result-none",
	"result-spam",
];

/**
 * sessionStorage, not localStorage: a reload should restore the run, but a scan is
 * a snapshot and shouldn't outlive the tab presenting stale results as current.
 */
export function loadState(): PersistedState | null {
	try {
		const raw = window.sessionStorage.getItem(STORAGE_KEY);

		if (!raw) {
			return null;
		}

		const parsed = JSON.parse(raw) as PersistedState;

		if (!VALID_STEPS.includes(parsed?.step)) {
			return null;
		}

		// A half payload would render a step with no rows and no way to refetch.
		if (parsed.scan && !Array.isArray(parsed.scan.sections)) {
			return null;
		}

		return parsed;
	} catch (error) {
		// Unavailable (private mode) or unparseable: start fresh.
		return null;
	}
}

export function saveState(state: PersistedState): void {
	try {
		window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state));
	} catch (error) {
		// Persisting is a convenience; losing it must never break the wizard.
	}
}
