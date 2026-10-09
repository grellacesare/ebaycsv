import { render,screen,fireEvent } from '@testing-library/react';
import { test,expect } from 'vitest';
import { HelpField } from '../components/HelpField';
test('shows_help_on_hover_focus_and_click',()=>{
  render(<HelpField id="shipping" label="Spedizione" help="Costo IVA esclusa, incluso nel prezzo."><input id="shipping"/></HelpField>);
  const help=screen.getByRole('button',{name:'Aiuto: Spedizione'});
  fireEvent.mouseEnter(help);expect(screen.getByRole('tooltip')).toHaveTextContent('IVA esclusa');
  fireEvent.mouseLeave(help);expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
  fireEvent.focus(help);expect(screen.getByRole('tooltip')).toBeVisible();
  fireEvent.keyDown(help,{key:'Escape'});expect(screen.queryByRole('tooltip')).not.toBeInTheDocument();
  fireEvent.click(help);expect(screen.getByRole('tooltip')).toBeVisible();
});
