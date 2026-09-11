import {
	Box,
	Button,
	Flex,
	Modal,
	ModalBody,
	ModalCloseButton,
	ModalContent,
	ModalFooter,
	ModalHeader,
	ModalOverlay,
	Textarea,
	useToast,
} from "@chakra-ui/react";
import { __ } from "@wordpress/i18n";
import { useRef } from "react";
import { FiCopy, FiDownload, FiLock } from "react-icons/fi";
import Text from "./Text";

interface ReportModalProps {
	isOpen: boolean;
	onClose: () => void;
	report: string;
}

const ReportModal = ({ isOpen, onClose, report }: ReportModalProps) => {
	const toast = useToast();
	const textareaRef = useRef<HTMLTextAreaElement>(null);

	const handleCopy = async () => {
		let copied = false;

		// The Clipboard API needs a secure context (https, or the literal
		// hostname "localhost") — plain http:// admin sites like this one
		// don't get it, so `navigator.clipboard` is simply undefined there.
		if (navigator.clipboard && window.isSecureContext) {
			try {
				await navigator.clipboard.writeText(report);
				copied = true;
			} catch (error) {
				copied = false;
			}
		}

		if (!copied && textareaRef.current) {
			textareaRef.current.focus();
			textareaRef.current.select();
			try {
				copied = document.execCommand("copy");
			} catch (error) {
				copied = false;
			}
		}

		toast({
			title: copied
				? __("Copied", "user-registration")
				: __("Couldn't copy automatically. The text is selected, so press Ctrl/Cmd+C.", "user-registration"),
			status: copied ? "success" : "warning",
			duration: copied ? 1600 : 4000,
			isClosable: true,
		});
	};

	const handleDownload = () => {
		const blob = new Blob([report], { type: "text/plain" });
		const anchor = document.createElement("a");
		anchor.href = URL.createObjectURL(blob);
		anchor.download = "email-health-report.txt";
		anchor.click();
		URL.revokeObjectURL(anchor.href);
	};

	return (
		<Modal isOpen={isOpen} onClose={onClose} isCentered size="lg">
			<ModalOverlay />
			<ModalContent>
				<ModalHeader pb="2px">
					{__("Send this to support", "user-registration")}
					<Text fontSize="12.5px" fontWeight="400" color="gray.500" mt="4px">
						{__(
							"Everything below was gathered from this scan. Paste it into your ticket and support can skip the back-and-forth.",
							"user-registration"
						)}
					</Text>
				</ModalHeader>
				<ModalCloseButton />
				<ModalBody>
					<Textarea
						ref={textareaRef}
						value={report}
						readOnly
						fontFamily="mono"
						fontSize="11.8px"
						lineHeight="1.7"
						height="300px"
						bg="gray.50"
						color="gray.600"
						borderColor="gray.200"
					/>
					<Flex align="center" gap="8px" mt="10px" color="gray.500">
						<Box flexShrink={0} mt="1px">
							<FiLock size={13} />
						</Box>
						<Text fontSize="11.5px" lineHeight="1.5">
							{__(
								"No passwords or API keys are included, because the scan never reads them.",
								"user-registration"
							)}
						</Text>
					</Flex>
				</ModalBody>
				<ModalFooter gap="10px">
					<Button variant="outline" fontSize="13.5px" fontWeight="600" onClick={handleDownload} leftIcon={<FiDownload size={14} />}>
						{__("Download .txt", "user-registration")}
					</Button>
					<Button colorScheme="primary" fontSize="13.5px" fontWeight="600" onClick={handleCopy} leftIcon={<FiCopy size={14} />}>
						{__("Copy to clipboard", "user-registration")}
					</Button>
				</ModalFooter>
			</ModalContent>
		</Modal>
	);
};

export default ReportModal;
