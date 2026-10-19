// Section colours. These defaults are used until the colours set in the admin
// (Structure > Sections) have been fetched from /api/sections, and for sections
// that have none there.
export const sectionColors: { [key: string]: string } = {
  sf: '#FFB74D', // Bright Orange
  sv: '#64B5F6', // Bright Blue
  'c-m': '#81C784', // Bright Green
  'c-w': '#FFF176', // Bright Yellow
  'p-m': '#F06292', // Bright Pink
  'p-w': '#BA68C8', // Bright Purple
  default: '#E0E0E0', // A slightly darker gray for contrast
};

// Replaces the section colours with the ones from the admin (name -> #rrggbb).
export const setSectionColors = (colors: { [key: string]: string }): void => {
  for (const [name, colour] of Object.entries(colors)) {
    if (/^#[0-9a-fA-F]{6}$/.test(colour)) sectionColors[name] = colour;
  }
};

export const getSectionColor = (section: string): string => {
  return sectionColors[section] || sectionColors.default;
};
