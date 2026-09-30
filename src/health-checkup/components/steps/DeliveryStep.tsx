import { __ } from "@wordpress/i18n";
import { CheckSection } from "../../api/healthCheckupApi";
import ScanScreen from "../ScanScreen";

interface DeliveryStepProps {
	section: CheckSection | null;
	isLoading: boolean;
	error: string;
	onBack: () => void;
	onNext: () => void;
	onResolved: () => void;
}

/**
 * Step 3, the route mail takes and whether the site may send as its "From"
 * address. Sits before the live test that confirms it.
 */
const DeliveryStep = ({
	section,
	isLoading,
	error,
	onBack,
	onNext,
	onResolved,
}: DeliveryStepProps) => (
	<ScanScreen
		loadingHeading={__("Checking how your mail is sent…", "user-registration")}
		heading={__("How your mail leaves this site", "user-registration")}
		loadingBlurb={__(
			"Working out the route mail takes and what your domain publishes about it.",
			"user-registration"
		)}
		section={section}
		// Findings only; the remedies are made on the result screen.
		showFixes={false}
		isLoading={isLoading}
		error={error}
		onNext={onNext}
		onBack={onBack}
		onResolved={onResolved}
	/>
);

export default DeliveryStep;
