

export const sectionColors: { [key: string]: string } = {
  sf: '#FFB74D', // Bright Orange
  sm: '#64B5F6', // Bright Blue
  'c-m': '#81C784', // Bright Green
  'c-w': '#FFF176', // Bright Yellow
  'p-m': '#F06292', // Bright Pink
  'p-w': '#BA68C8', // Bright Purple
  default: '#E0E0E0', // A slightly darker gray for contrast
};

export const laborColors: { [key: string]: string } = {
  sm: '#4DD0E1',   // Bright Cyan
  sf: '#FF8A65',   // Bright Coral
  sscc: '#AED581', // Bright Light Green
  'c-m': '#DCE775', // Bright Lime
  'c-w': '#9575CD', // Bright Deep Purple
  'p-m': '#E57373', // Bright Red
  'p-w': '#7986CB', // Bright Indigo
  default: '#FAFAFA', // Off-white
};


export const getSectionColor = (section: string): string => {
  return sectionColors[section] || sectionColors.default;
};

export const getLaborColor = (labor: string): string => {
  return laborColors[labor] || laborColors.default;
};
