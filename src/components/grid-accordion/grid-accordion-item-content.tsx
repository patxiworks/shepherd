
import type { AccordionGroupData } from '@/types';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

interface GridAccordionItemContentProps {
  item: AccordionGroupData;
  groupBy: 'centre' | 'activity' | 'date';
}

export function GridAccordionItemContent({ item, groupBy }: GridAccordionItemContentProps) {
  if (!item.items || item.items.length === 0) {
    return <p className="text-muted-foreground py-4 text-center">No scheduled items.</p>;
  }

  const getHeaders = () => {
    switch(groupBy) {
      case 'centre':
        return ['Activity', 'Day', 'Time', 'Priest'];
      case 'activity':
        return ['Centre', 'Day', 'Time', 'Priest'];
      case 'date':
        return ['Activity', 'Centre', 'Time', 'Priest'];
      default:
        return [];
    }
  };

  const getCellData = (activityItem: any) => {
     switch(groupBy) {
      case 'centre':
        return [activityItem.title, activityItem.day, activityItem.time, activityItem.priest];
      case 'activity':
        return [activityItem.centre, activityItem.day, activityItem.time, activityItem.priest];
       case 'date':
        return [activityItem.title, activityItem.centre, activityItem.time, activityItem.priest];
      default:
        return [];
    }
  }
  
  const headers = getHeaders();
  
  return (
    <div className="px-1 py-2 md:px-2">
      <Table>
        <TableHeader>
          <TableRow>
            {headers.map(header => <TableHead key={header}>{header}</TableHead>)}
          </TableRow>
        </TableHeader>
        <TableBody>
          {item.items.map((activityItem, index) => {
            const cells = getCellData(activityItem);
            return (
              <TableRow key={index}>
                <TableCell className="font-medium">{cells[0] || 'N/A'}</TableCell>
                <TableCell>{cells[1] || 'N/A'}</TableCell>
                <TableCell>{cells[2] || 'N/A'}</TableCell>
                <TableCell>{cells[3] || 'N/A'}</TableCell>
              </TableRow>
            )
          })}
        </TableBody>
      </Table>
    </div>
  );
}
