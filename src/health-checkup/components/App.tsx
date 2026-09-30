import { Box, ChakraProvider, extendTheme, Flex, IconButton, Image, Tooltip } from "@chakra-ui/react";
import { __ } from "@wordpress/i18n";
import { FiX } from "react-icons/fi";
import { useCallback, useEffect, useRef, useState } from "react";
import {
	confirmDelivery,
	DeliveryOutcome,
	runScan,
	ScanResult,
	sectionOf,
} from "../api/healthCheckupApi";
import { buildReport } from "../utils/buildReport";
import { loadState, saveState } from "../utils/persistedState";
import ReportModal from "./ReportModal";
import Stepper, { SCAN_STEPS, WizardStep } from "./Stepper";
import DeliveryStep from "./steps/DeliveryStep";
import IntroStep from "./steps/IntroStep";
import ResultStep, { ResultVariant } from "./steps/ResultStep";
import SettingsStep from "./steps/SettingsStep";
import TestDeliveryStep from "./steps/TestDeliveryStep";

// Inter first, as the setup wizard loads it. This is a takeover with no admin
// chrome, so it sets its own type.
const SANS_STACK = "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif";
const MONO_STACK = "ui-monospace, SFMono-Regular, Menlo, Consolas, monospace";

const theme = extendTheme({
	fonts: {
		heading: SANS_STACK,
		body: SANS_STACK,
		mono: MONO_STACK,
	},
	// From assets/css/variables/_colors.scss, shaded with the lighten()/darken()
	// steps _badge.scss uses, so a status reads the same as elsewhere.
	colors: {
		// $primary_color, matching the setup wizard (src/welcome/components/App.tsx).
		primary: {
			50: "#eef1ff",
			100: "#d4daff",
			200: "#b8c1ff",
			300: "#9ba8ff",
			400: "#7e8fff",
			500: "#475bb2",
			600: "#3a4b9c",
			700: "#2f3da6",
			800: "#252f89",
			900: "#1c246d",
		},
		// $green — pass.
		green: {
			50: "#e8f8e6", // lighten($green, 42%)
			200: "#cef0cb", // lighten($green, 35%)
			500: "#4cc741", // $green
			600: "#3aa530", // darken($green, 10%)
			700: "#3aa530",
		},
		// $red — issue.
		red: {
			50: "#fff4f4", // lighten($red, 38%) — row fills, deliberately faint
			100: "#ffe8e9", // lighten($red, 30%)
			200: "#ffb5b8", // lighten($red, 20%)
			500: "#ff4f55", // $red
			600: "#ff4f55", // _badge.scss uses the base tone for danger text.
		},
		// $orange — warning.
		orange: {
			50: "#fffbf0", // lighten($orange, 48%) — row fills, deliberately faint
			100: "#fff8e6", // lighten($orange, 45%)
			200: "#ffeab3", // lighten($orange, 35%)
			300: "#ffeab3",
			500: "#ffba00", // $orange
			700: "#b38200", // darken($orange, 15%)
		},
		// The plugin's grey ramp ($grey-15 → $grey-500, $border-color).
		gray: {
			50: "#fdfdfd",
			100: "#f4f4f4",
			200: "#e7e7e7",
			300: "#bdbdbd",
			400: "#bababa",
			500: "#999999",
			600: "#6b6b6b",
			700: "#383838",
			800: "#222222",
		},
	},
	components: {
		Button: {
			baseStyle: {
				borderRadius: "4px",
			},
		},
	},
});

/** The setup wizard's ground and bar height. */
const PAGE_BG = "#F8F8FA";
const HEADER_HEIGHT = "65px";

const RESULT_VARIANTS: Record<DeliveryOutcome, ResultVariant> = {
	arrived: "good",
	spam: "spam",
	none: "none",
};

// Read once: the wizard mounts a single time.
const restored = loadState();

// A scan is a snapshot of settings that live on other screens, so landing on a
// scan step always re-scans rather than trusting a restored result.
const needsInitialScan = !!restored && SCAN_STEPS.includes(restored.step);

const App = () => {
	const [step, setStep] = useState<WizardStep>(restored?.step ?? "intro");
	const [scan, setScan] = useState<ScanResult | null>(restored?.scan ?? null);
	// Incremented to request a scan; 0 means none asked for yet.
	const [scanToken, setScanToken] = useState(needsInitialScan ? 1 : 0);
	const [isScanning, setIsScanning] = useState(needsInitialScan);
	const [scanError, setScanError] = useState("");
	const [deliveryOutcome, setDeliveryOutcome] = useState<DeliveryOutcome | null>(
		restored?.deliveryOutcome ?? null
	);
	const [isReportOpen, setIsReportOpen] = useState(false);
	const [reportText, setReportText] = useState("");
	const [testEmailSent, setTestEmailSent] = useState(restored?.testEmailSent ?? false);
	const [sendError, setSendError] = useState<string | null>(restored?.sendError ?? null);
	const rootRef = useRef<HTMLDivElement>(null);

	// One scan per run, owned here: the two scan steps are views of one result.
	useEffect(() => {
		if (0 === scanToken) {
			return;
		}

		let cancelled = false;
		setIsScanning(true);
		setScanError("");

		runScan()
			.then((result) => {
				if (!cancelled) {
					setScan(result);
				}
			})
			.catch((error: Error) => {
				if (!cancelled) {
					setScanError(error.message);
				}
			})
			.finally(() => {
				if (!cancelled) {
					setIsScanning(false);
				}
			});

		return () => {
			cancelled = true;
		};
	}, [scanToken]);

	// Fix links open settings in a new tab, so coming back is not a page load.
	// Re-read on the way in, or the row just fixed keeps its old verdict.
	useEffect(() => {
		if (!SCAN_STEPS.includes(step)) {
			return;
		}

		const refreshWhenVisible = () => {
			if ("visible" === document.visibilityState) {
				setScanToken((token) => token + 1);
			}
		};

		document.addEventListener("visibilitychange", refreshWhenVisible);

		return () => document.removeEventListener("visibilitychange", refreshWhenVisible);
	}, [step]);

	// Survive a refresh; without this a completed run is lost.
	useEffect(() => {
		saveState({ step, scan, deliveryOutcome, testEmailSent, sendError });
	}, [step, scan, deliveryOutcome, testEmailSent, sendError]);

	// Stops Chrome's scroll-anchoring re-adjusting after a step change.
	useEffect(() => {
		document.documentElement.style.overflowAnchor = "none";
	}, []);

	// Scroll each step to its top; first mount is already correct.
	const isFirstRender = useRef(true);
	useEffect(() => {
		if (isFirstRender.current) {
			isFirstRender.current = false;
			return;
		}
		rootRef.current?.scrollIntoView({ block: "start" });
	}, [step]);

	// Clears the old result too, so a mid-run refresh can't resurrect it.
	const clearRun = () => {
		setTestEmailSent(false);
		setScan(null);
		setScanError("");
		setDeliveryOutcome(null);
		setSendError(null);
	};

	// From the start screen: begin the run and fetch the scan behind it.
	const beginRun = () => {
		clearRun();
		setStep("settings");
		setScanToken((token) => token + 1);
	};

	// Back to step 1, not step 2: otherwise the start screen is unreachable.
	const restartRun = () => {
		clearRun();
		setStep("intro");
	};

	// Re-read after an inline fix. Rows stay on screen while it runs.
	const rescan = useCallback(() => setScanToken((token) => token + 1), []);

	const openReport = () => {
		setReportText(
			buildReport(
				scan?.checks ?? [],
				deliveryOutcome,
				scan?.summary ?? null,
				scan?.sections ?? [],
				scan?.dns_checks ?? []
			)
		);
		setIsReportOpen(true);
	};

	// Nothing to confirm, so record it here and carry the error through.
	const handleSendFailure = (error: string) => {
		// Built for an admin notice, so it can carry markup RichText won't parse.
		setSendError(error.replace(/<[^>]*>/g, "").replace(/\s+/g, " ").trim());
		setDeliveryOutcome("none");
		confirmDelivery("none").catch(() => undefined);
		setStep("result-none");
	};

	const handleChoice = (outcome: DeliveryOutcome) => {
		setSendError(null);
		setDeliveryOutcome(outcome);
		confirmDelivery(outcome).catch(() => undefined);
		setStep(
			outcome === "arrived" ? "result-good" : outcome === "spam" ? "result-spam" : "result-none"
		);
	};

	// Only a run with nothing to show yet gets the progress bar.
	const isLoading = isScanning && !scan;

	const renderStep = () => {
		switch (step) {
			case "intro":
				return <IntroStep onStart={beginRun} />;
			case "settings":
				return (
					<SettingsStep
						section={scan ? sectionOf(scan.sections, "settings") : null}
						isLoading={isLoading}
						error={scanError}
						onBack={() => setStep("intro")}
						onNext={() => setStep("delivery")}
						onResolved={rescan}
					/>
				);
			case "delivery":
				return (
					<DeliveryStep
						section={scan ? sectionOf(scan.sections, "delivery") : null}
						isLoading={isLoading}
						error={scanError}
						onBack={() => setStep("settings")}
						onNext={() => setStep("test")}
						onResolved={rescan}
					/>
				);
			case "test":
				return (
					<TestDeliveryStep
						onChoice={handleChoice}
						alreadySent={testEmailSent}
						onSent={() => setTestEmailSent(true)}
						onSendFailed={handleSendFailure}
					/>
				);
			case "result-good":
			case "result-none":
			case "result-spam": {
				const variant: ResultVariant = deliveryOutcome
					? RESULT_VARIANTS[deliveryOutcome]
					: "good";

				return (
					<ResultStep
						variant={variant}
						checks={scan?.checks ?? []}
						onRunAgain={restartRun}
						onOpenReport={openReport}
						smartSmtpStatus={scan?.smartsmtp_status ?? "not_installed"}
						smtpPlugin={scan?.smtp_plugin ?? null}
						sendError={sendError}
					/>
				);
			}
			default:
				return null;
		}
	};

	// Full-page takeover on the setup wizard's measurements: 65px bar, 920px
	// card, #F8F8FA ground.
	return (
		<ChakraProvider theme={theme}>
			{/* Named here, not left to the cascade: the wizard stylesheet also sets a
			    body font, and which wins depends on stylesheet order. */}
			<Box minH="100vh" bg={PAGE_BG} fontFamily="body" sx={{ overflowAnchor: "none" }}>
				<Flex
					position="fixed"
					top={0}
					left={0}
					right={0}
					zIndex={1000}
					bg="white"
					borderBottomWidth="1px"
					borderColor="gray.200"
					align="center"
					justify="space-between"
					gap="16px"
					py={3}
					px={{ base: 3, md: 4, lg: 6 }}
				>
					<Flex align="center" gap="10px" flexShrink={0}>
						<Image src={window._UR_EMAIL_HEALTH_.logoUrl} alt="" h="30px" w="auto" />
						<Box fontSize="15px" fontWeight="600" color="gray.800" display={{ base: "none", md: "block" }}>
							{__("Email Delivery Checkup", "user-registration")}
						</Box>
					</Flex>

					{/* Centred on the card's axis, pinned so the logo and close can't
					    shift it. */}
					<Flex
						position={{ base: "relative", lg: "absolute" }}
						left={{ base: "auto", lg: "50%" }}
						transform={{ base: "none", lg: "translateX(-50%)" }}
						align="center"
						justify="center"
						w="100%"
						maxW="920px"
						px={{ base: 3, md: 4 }}
					>
						<Stepper step={step} />
					</Flex>

					<Tooltip label={__("Close", "user-registration")} hasArrow placement="bottom">
						<IconButton
							as="a"
							href={window._UR_EMAIL_HEALTH_.exitUrl}
							aria-label={__("Close the checkup", "user-registration")}
							icon={<FiX size={20} />}
							variant="ghost"
							color="#909090"
							_hover={{ color: "gray.700", bg: "gray.100" }}
							flexShrink={0}
						/>
					</Tooltip>
				</Flex>

				<Box pt={HEADER_HEIGHT}>
					<Flex justify="center" align="flex-start" px={{ base: 3, md: 4 }} py={{ base: 6, md: 10 }}>
						<Box
							ref={rootRef}
							w="100%"
							maxW="920px"
							bg="white"
							borderWidth="1px"
							borderColor="#F4F4F4"
							borderRadius="8px"
							px={{ base: 4, md: 8 }}
							py={{ base: 5, md: 6 }}
							boxShadow="0 10px 15px -3px rgba(0, 0, 0, 0.06)"
						>
							{renderStep()}
						</Box>
					</Flex>
				</Box>
			</Box>

			<ReportModal isOpen={isReportOpen} onClose={() => setIsReportOpen(false)} report={reportText} />
		</ChakraProvider>
	);
};

export default App;
