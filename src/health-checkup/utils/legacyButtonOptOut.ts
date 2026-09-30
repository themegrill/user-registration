/**
 * `_button.scss` styles every bare `<button>` inside `.user-registration` with a
 * shadow and `+ button { margin-left: 6px }`, which indents sibling buttons. It
 * exempts `.chakra-button`, so wearing the class is how a `Flex as="button"`
 * opts out. Applied as a className because the selector's specificity (0,2,1)
 * beats any Chakra style prop.
 */
export const LEGACY_BUTTON_OPT_OUT = "chakra-button";
